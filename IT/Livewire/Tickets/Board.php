<?php

namespace App\Modules\Operation\IT\Livewire\Tickets;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Services\TransitionManager;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Operation\IT\Livewire\Concerns\PresentsTicketBadges;
use App\Modules\Operation\IT\Models\Ticket;
use App\Modules\Operation\IT\Services\TicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kanban board for the IT ticket queue.
 *
 * Columns come from the workflow engine's KanbanColumn config; cards move
 * by drag & drop, but only along edges that exist in the transition graph.
 */
class Board extends Component
{
    use InteractsWithNotifications, PresentsTicketBadges;

    /**
     * Done is a recent-wins column, not an archive: only tickets that
     * finished within this window appear.
     */
    private const int DONE_WINDOW_DAYS = 14;

    #[Url]
    public bool $mineOnly = false;

    #[Url]
    public string $priorityFilter = '';

    /**
     * Move a ticket along a workflow edge (card dropped on a column).
     */
    public function moveTicket(int $ticketId, string $toCode): void
    {
        if (! $this->allowed('operations.it.ticket.update')) {
            $this->notifyError(__('You do not have permission to update tickets.'));

            return;
        }

        $ticket = $this->findTicket($ticketId);

        if ($ticket === null) {
            $this->notifyError(__('Ticket not found.'));

            return;
        }

        $result = app(TicketService::class)->transition($ticket, Actor::forUser(Auth::user()), $toCode);

        if ($result->success) {
            $this->notify(__('#:id moved to :status.', ['id' => $ticket->id, 'status' => $this->statusLabel($toCode)]));
        } else {
            $this->notifyError($result->reason ?? __('Transition failed.'));
        }
    }

    /**
     * Assign a ticket to an employee (card dropped on Up Next).
     */
    public function assignTicket(int $ticketId, int $employeeId): void
    {
        $ticket = $this->findTicket($ticketId);

        if ($ticket === null) {
            $this->notifyError(__('Ticket not found.'));

            return;
        }

        if (! $this->allowed('operations.it.ticket.assign')) {
            $this->notifyError(__('You do not have permission to assign tickets.'));

            return;
        }

        $assignee = Employee::query()
            ->where('company_id', $ticket->company_id)
            ->where('status', 'active')
            ->find($employeeId);

        if ($assignee === null) {
            $this->notifyError(__('That employee cannot be assigned.'));

            return;
        }

        $result = app(TicketService::class)->assign($ticket, Actor::forUser(Auth::user()), $assignee);

        if ($result->success) {
            $this->notify(__('#:id assigned to :name.', ['id' => $ticket->id, 'name' => $assignee->displayName()]));
        } else {
            $this->notifyError($result->reason ?? __('Assignment failed.'));
        }
    }

    public function render(): View
    {
        $statuses = StatusConfig::query()
            ->forFlow(Ticket::FLOW)
            ->active()
            ->orderBy('position')
            ->get();

        $columns = KanbanColumn::query()
            ->forFlow(Ticket::FLOW)
            ->active()
            ->get();

        $tickets = $this->tickets();
        $statusColumn = $statuses->pluck('kanban_code', 'code');

        return view('operation-it::livewire.it.tickets.board', [
            'columns' => $columns,
            'ticketsByColumn' => $tickets->groupBy(fn (Ticket $ticket): string => $statusColumn[$ticket->status] ?? ''),
            'transitionMap' => $this->transitionMap($statuses, $statusColumn),
            'statusColumn' => $statusColumn,
            'assigneeOptions' => $this->assigneeOptions(),
        ]);
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function tickets(): Collection
    {
        $query = Ticket::query()
            ->where('company_id', $this->companyId())
            ->where(function ($builder): void {
                $builder->whereNotIn('status', Ticket::DONE_STATUSES)
                    ->orWhere('resolved_at', '>=', Carbon::now()->subDays(self::DONE_WINDOW_DAYS));
            })
            ->with('assignee', 'reporter')
            ->orderByRaw(Ticket::priorityRankSql().' desc')
            ->orderBy('created_at');

        if ($this->mineOnly) {
            $query->where('assignee_id', $this->myEmployeeId() ?? 0);
        }

        if ($this->priorityFilter !== '') {
            $query->where('priority', $this->priorityFilter);
        }

        return $query->get();
    }

    /**
     * Legal moves per status, annotated with the target kanban column so
     * the client can resolve a drop without asking the server.
     *
     * @param  Collection<int, StatusConfig>  $statuses
     * @param  Collection<string, string>  $statusColumn
     * @return array<string, array<int, array{to: string, label: string, column: string, needsAssignee: bool}>>
     */
    private function transitionMap(Collection $statuses, Collection $statusColumn): array
    {
        $manager = app(TransitionManager::class);

        return $statuses->mapWithKeys(fn (StatusConfig $status): array => [
            $status->code => $manager->getAvailableTransitions(Ticket::FLOW, $status->code)
                ->filter(fn (StatusTransition $transition): bool => $transition->capability === null || $this->allowed($transition->capability))
                ->map(fn (StatusTransition $transition): array => [
                    'to' => $transition->to_code,
                    'label' => $transition->resolveLabel(),
                    'column' => $statusColumn[$transition->to_code] ?? '',
                    'needsAssignee' => $transition->to_code === 'assigned',
                ])
                ->values()
                ->all(),
        ])->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function assigneeOptions(): array
    {
        return Employee::query()
            ->where('company_id', $this->companyId())
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get()
            ->map(fn (Employee $employee): array => [
                'value' => (string) $employee->id,
                'label' => $employee->displayName(),
            ])
            ->all();
    }

    private function findTicket(int $ticketId): ?Ticket
    {
        return Ticket::query()
            ->where('company_id', $this->companyId())
            ->find($ticketId);
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

    private function allowed(string $capability): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), $capability)
            ->allowed;
    }
}
