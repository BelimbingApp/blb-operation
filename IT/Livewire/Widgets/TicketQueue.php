<?php

namespace App\Modules\Operation\IT\Livewire\Widgets;

use App\Base\Dashboard\Widget;
use App\Modules\Operation\IT\Livewire\Concerns\PresentsTicketBadges;
use App\Modules\Operation\IT\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard widget: IT ticket queue health at a glance.
 *
 * Three numbers (open, mine, waiting) and the tickets that most need a
 * human — unassigned work first, then anything blocked on input.
 * Visibility is gated by `operations.it.ticket.list` in Config/dashboard.php.
 */
class TicketQueue extends Widget
{
    use PresentsTicketBadges;

    private const int ATTENTION_LIMIT = 4;

    public function render(): View
    {
        return view('operation-it::livewire.it.tickets.widgets.queue', [
            'stats' => $this->stats(),
            'attention' => $this->attentionTickets(),
        ]);
    }

    /**
     * @return array{open: int, mine: int, waiting: int}
     */
    private function stats(): array
    {
        $row = Ticket::query()
            ->where('company_id', $this->companyId())
            ->selectRaw("sum(case when status = 'open' then 1 else 0 end) as open_count")
            ->selectRaw("sum(case when status in ('assigned', 'in_progress', 'blocked', 'awaiting_parts', 'review') and assignee_id = ? then 1 else 0 end) as mine_count", [$this->myEmployeeId() ?? 0])
            ->selectRaw("sum(case when status in ('blocked', 'awaiting_parts') then 1 else 0 end) as waiting_count")
            ->first();

        return [
            'open' => (int) ($row->open_count ?? 0),
            'mine' => (int) ($row->mine_count ?? 0),
            'waiting' => (int) ($row->waiting_count ?? 0),
        ];
    }

    /**
     * Tickets that most need a human: unassigned open work by severity and
     * age, then blocked tickets waiting on input.
     *
     * @return Collection<int, Ticket>
     */
    private function attentionTickets(): Collection
    {
        return Ticket::query()
            ->where('company_id', $this->companyId())
            ->where(function ($builder): void {
                $builder->where(fn ($open) => $open->where('status', 'open')->whereNull('assignee_id'))
                    ->orWhere('status', 'blocked');
            })
            ->orderByRaw("case when status = 'blocked' then 1 else 0 end")
            ->orderByRaw(Ticket::priorityRankSql().' desc')
            ->orderBy('created_at')
            ->limit(self::ATTENTION_LIMIT)
            ->get();
    }

    private function companyId(): ?int
    {
        return Auth::user()?->getCompanyId();
    }

    private function myEmployeeId(): ?int
    {
        $employeeId = Auth::user()?->employee_id;

        return $employeeId !== null ? (int) $employeeId : null;
    }
}
