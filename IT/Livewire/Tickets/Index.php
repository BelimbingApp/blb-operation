<?php

namespace App\Modules\Operation\IT\Livewire\Tickets;

use App\Base\Foundation\Livewire\SearchablePaginatedList;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Operation\IT\Livewire\Concerns\PresentsTicketBadges;
use App\Modules\Operation\IT\Models\Ticket;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class Index extends SearchablePaginatedList
{
    use PresentsTicketBadges;

    protected const string VIEW_NAME = 'operation-it::livewire.it.tickets.index';

    protected const string VIEW_DATA_KEY = 'tickets';

    protected const string SORT_COLUMN = 'created_at';

    /**
     * Queue lenses: which slice of the queue the list shows.
     */
    public const array SCOPES = ['open', 'mine', 'unassigned', 'done', 'all'];

    public string $search = '';

    #[Url]
    public string $scope = 'open';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $priorityFilter = '';

    #[Url]
    public string $categoryFilter = '';

    #[Url]
    public string $assigneeFilter = '';

    public function setScope(string $scope): void
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return;
        }

        $this->scope = $scope;
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPriorityFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAssigneeFilter(): void
    {
        $this->resetPage();
    }

    protected function sortableColumns(): array
    {
        return [
            'id' => 'operation_it_tickets.id',
            'title' => 'operation_it_tickets.title',
            'reporter_name' => 'reporter_employee.full_name',
            'assignee_name' => 'assignee_employee.full_name',
            'priority' => 'priority_rank',
            'status' => 'operation_it_tickets.status',
            'category' => 'operation_it_tickets.category',
            'created_at' => 'operation_it_tickets.created_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'id' => 'desc',
            'title' => 'asc',
            'reporter_name' => 'asc',
            'assignee_name' => 'asc',
            'priority' => 'desc',
            'status' => 'asc',
            'category' => 'asc',
            'created_at' => 'desc',
        ];
    }

    protected function query(): EloquentBuilder|QueryBuilder
    {
        $query = Ticket::query()
            ->select('operation_it_tickets.*')
            ->selectRaw(Ticket::priorityRankSql('operation_it_tickets.priority').' as priority_rank')
            ->leftJoin('employees as reporter_employee', 'operation_it_tickets.reporter_id', '=', 'reporter_employee.id')
            ->leftJoin('employees as assignee_employee', 'operation_it_tickets.assignee_id', '=', 'assignee_employee.id')
            ->where('operation_it_tickets.company_id', $this->companyId())
            ->with('reporter', 'assignee');

        $this->applyScope($query);

        if ($this->statusFilter !== '') {
            $query->where('operation_it_tickets.status', $this->statusFilter);
        }

        if ($this->priorityFilter !== '') {
            $query->where('operation_it_tickets.priority', $this->priorityFilter);
        }

        if ($this->categoryFilter !== '') {
            $query->where('operation_it_tickets.category', $this->categoryFilter);
        }

        if ($this->assigneeFilter === 'none') {
            $query->whereNull('operation_it_tickets.assignee_id');
        } elseif ($this->assigneeFilter !== '') {
            $query->where('operation_it_tickets.assignee_id', (int) $this->assigneeFilter);
        }

        return $query;
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function (EloquentBuilder $builder) use ($search): void {
            $builder->where('operation_it_tickets.title', 'like', '%'.$search.'%')
                ->orWhere('operation_it_tickets.description', 'like', '%'.$search.'%')
                ->orWhere('operation_it_tickets.location', 'like', '%'.$search.'%')
                ->orWhereHas('reporter', function (EloquentBuilder $reporterQuery) use ($search): void {
                    $reporterQuery->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('short_name', 'like', '%'.$search.'%');
                })
                ->orWhereHas('assignee', function (EloquentBuilder $assigneeQuery) use ($search): void {
                    $assigneeQuery->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('short_name', 'like', '%'.$search.'%');
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraViewData(): array
    {
        return [
            'stats' => $this->queueStats(),
            'assigneeOptions' => $this->assigneeOptions(),
        ];
    }

    private function applyScope(EloquentBuilder $query): void
    {
        match ($this->scope) {
            'mine' => $query->whereIn('operation_it_tickets.status', Ticket::OPEN_STATUSES)
                ->where('operation_it_tickets.assignee_id', $this->myEmployeeId()),
            'unassigned' => $query->whereIn('operation_it_tickets.status', Ticket::OPEN_STATUSES)
                ->whereNull('operation_it_tickets.assignee_id'),
            'done' => $query->whereIn('operation_it_tickets.status', Ticket::DONE_STATUSES),
            'all' => null,
            default => $query->whereIn('operation_it_tickets.status', Ticket::OPEN_STATUSES),
        };
    }

    /**
     * Queue-health figures for the stat strip. Company-wide on purpose:
     * they describe the queue, not the current filter result.
     *
     * @return array{open: int, unassigned: int, active: int, mine: int, waiting: int, blocked: int, resolved_week: int}
     */
    private function queueStats(): array
    {
        $weekAgo = Carbon::now()->subDays(7);

        $row = Ticket::query()
            ->where('company_id', $this->companyId())
            ->selectRaw("sum(case when status = 'open' then 1 else 0 end) as open_count")
            ->selectRaw("sum(case when status = 'open' and assignee_id is null then 1 else 0 end) as unassigned_count")
            ->selectRaw("sum(case when status in ('assigned', 'in_progress', 'review') then 1 else 0 end) as active_count")
            ->selectRaw("sum(case when status in ('assigned', 'in_progress', 'review') and assignee_id = ? then 1 else 0 end) as mine_count", [$this->myEmployeeId() ?? 0])
            ->selectRaw("sum(case when status in ('blocked', 'awaiting_parts') then 1 else 0 end) as waiting_count")
            ->selectRaw("sum(case when status = 'blocked' then 1 else 0 end) as blocked_count")
            ->selectRaw('sum(case when resolved_at >= ? then 1 else 0 end) as resolved_week_count', [$weekAgo])
            ->first();

        return [
            'open' => (int) ($row->open_count ?? 0),
            'unassigned' => (int) ($row->unassigned_count ?? 0),
            'active' => (int) ($row->active_count ?? 0),
            'mine' => (int) ($row->mine_count ?? 0),
            'waiting' => (int) ($row->waiting_count ?? 0),
            'blocked' => (int) ($row->blocked_count ?? 0),
            'resolved_week' => (int) ($row->resolved_week_count ?? 0),
        ];
    }

    /**
     * Active employees of this company for the assignee filter.
     *
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
