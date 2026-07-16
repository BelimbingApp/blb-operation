<?php

namespace App\Modules\Operation\IT\Database\Seeders;

use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Modules\Operation\IT\Models\Ticket;
use App\Modules\Operation\IT\Workflow\MarkTicketResolved;
use App\Modules\Operation\IT\Workflow\RequiresAssigneeGuard;
use App\Modules\Operation\IT\Workflow\ReturnTicketToQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

class TicketWorkflowSeeder extends Seeder
{
    /**
     * The flow identifier for the IT Ticket workflow.
     */
    private const FLOW = 'it_ticket';

    /**
     * Seed the IT Ticket workflow: registry, statuses, transitions, and kanban columns.
     */
    public function run(): void
    {
        $this->seedWorkflow();
        $this->seedStatuses();
        $this->seedTransitions();
        $this->seedKanbanColumns();
    }

    /**
     * Register the workflow in the process registry.
     */
    private function seedWorkflow(): void
    {
        Workflow::query()->updateOrCreate(
            ['code' => self::FLOW],
            [
                'label' => 'IT Ticket',
                'module' => 'it_ticket',
                'description' => 'IT support ticket lifecycle — from open to resolution.',
                'model_class' => Ticket::class,
                'is_active' => true,
            ],
        );
    }

    /**
     * Seed the status nodes for the IT Ticket workflow.
     *
     * `notifications.on_enter` lists the stakeholders the Base workflow
     * listener notifies when a ticket enters the status; the reporter
     * follows every move, the assignee hears about ownership and outcome.
     */
    private function seedStatuses(): void
    {
        $statuses = [
            ['code' => 'open',           'label' => 'Open',           'position' => 0, 'kanban_code' => 'open',        'notifications' => ['on_enter' => ['reporter'], 'channels' => ['database']]],
            ['code' => 'assigned',       'label' => 'Assigned',       'position' => 1, 'kanban_code' => 'up_next',     'notifications' => ['on_enter' => ['reporter', 'assignee'], 'channels' => ['database']]],
            ['code' => 'in_progress',    'label' => 'In Progress',    'position' => 2, 'kanban_code' => 'in_progress', 'notifications' => ['on_enter' => ['reporter'], 'channels' => ['database']]],
            ['code' => 'blocked',        'label' => 'Blocked',        'position' => 3, 'kanban_code' => 'waiting',     'notifications' => ['on_enter' => ['reporter', 'assignee'], 'channels' => ['database']]],
            ['code' => 'awaiting_parts', 'label' => 'Awaiting Parts', 'position' => 4, 'kanban_code' => 'waiting',     'notifications' => ['on_enter' => ['reporter'], 'channels' => ['database']]],
            ['code' => 'review',         'label' => 'Review',         'position' => 5, 'kanban_code' => 'review',      'notifications' => ['on_enter' => ['reporter'], 'channels' => ['database']]],
            ['code' => 'resolved',       'label' => 'Resolved',       'position' => 6, 'kanban_code' => 'done',        'notifications' => ['on_enter' => ['reporter', 'assignee'], 'channels' => ['database']]],
            ['code' => 'closed',         'label' => 'Closed',         'position' => 7, 'kanban_code' => 'done',        'notifications' => ['on_enter' => ['reporter'], 'channels' => ['database']]],
        ];

        foreach ($statuses as $status) {
            StatusConfig::query()->updateOrCreate(
                ['flow' => self::FLOW, 'code' => $status['code']],
                array_merge($status, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }

    /**
     * Seed the transition edges for the IT Ticket workflow.
     */
    private function seedTransitions(): void
    {
        $transitions = [
            ['from_code' => 'open',            'to_code' => 'assigned',       'label' => 'Assign',              'capability' => 'operations.it.ticket.assign', 'guard_class' => RequiresAssigneeGuard::class, 'position' => 0],
            ['from_code' => 'assigned',        'to_code' => 'in_progress',    'label' => 'Start Work',          'position' => 0],
            ['from_code' => 'assigned',        'to_code' => 'open',           'label' => 'Return to Queue',     'capability' => 'operations.it.ticket.assign', 'action_class' => ReturnTicketToQueue::class, 'position' => 1],
            ['from_code' => 'in_progress',     'to_code' => 'awaiting_parts', 'label' => 'Await Parts',         'position' => 0],
            ['from_code' => 'awaiting_parts',  'to_code' => 'in_progress',    'label' => 'Resume',              'position' => 0],
            ['from_code' => 'in_progress',     'to_code' => 'blocked',        'label' => 'Block — Needs Input', 'position' => 1],
            ['from_code' => 'blocked',         'to_code' => 'in_progress',    'label' => 'Unblock',             'position' => 0],
            ['from_code' => 'in_progress',     'to_code' => 'review',         'label' => 'Submit for Review',   'position' => 2],
            ['from_code' => 'review',          'to_code' => 'resolved',       'label' => 'Approve',             'action_class' => MarkTicketResolved::class, 'position' => 0],
            ['from_code' => 'review',          'to_code' => 'in_progress',    'label' => 'Request Rework',      'position' => 1],
            ['from_code' => 'in_progress',     'to_code' => 'resolved',       'label' => 'Resolve',             'action_class' => MarkTicketResolved::class, 'position' => 3],
            ['from_code' => 'resolved',        'to_code' => 'closed',         'label' => 'Close',               'position' => 0],
            ['from_code' => 'resolved',        'to_code' => 'open',           'label' => 'Reopen',              'action_class' => ReturnTicketToQueue::class, 'position' => 1],
        ];

        foreach ($transitions as $transition) {
            StatusTransition::query()->updateOrCreate(
                ['flow' => self::FLOW, 'from_code' => $transition['from_code'], 'to_code' => $transition['to_code']],
                array_merge(
                    ['capability' => null, 'guard_class' => null, 'action_class' => null],
                    $transition,
                    ['flow' => self::FLOW, 'is_active' => true],
                ),
            );
        }

        $this->deleteStaleRows(StatusTransition::query(), collect($transitions)
            ->map(fn (array $transition): string => $transition['from_code'].'→'.$transition['to_code'])
            ->all(), fn ($row): string => $row->from_code.'→'.$row->to_code);
    }

    /**
     * Seed the kanban board columns for the IT Ticket workflow.
     *
     * Columns mirror the real stages of support work; waiting states and
     * terminal states share a column to keep the board scannable.
     */
    private function seedKanbanColumns(): void
    {
        $columns = [
            ['code' => 'open',        'label' => 'Open',        'position' => 0],
            ['code' => 'up_next',     'label' => 'Up Next',     'position' => 1],
            ['code' => 'in_progress', 'label' => 'In Progress', 'position' => 2],
            ['code' => 'waiting',     'label' => 'Waiting',     'position' => 3],
            ['code' => 'review',      'label' => 'Review',      'position' => 4],
            ['code' => 'done',        'label' => 'Done',        'position' => 5],
        ];

        foreach ($columns as $column) {
            KanbanColumn::query()->updateOrCreate(
                ['flow' => self::FLOW, 'code' => $column['code']],
                array_merge($column, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }

        $this->deleteStaleRows(KanbanColumn::query(), array_column($columns, 'code'), fn ($row): string => $row->code);
    }

    /**
     * Remove rows this seeder no longer declares (renamed columns, dropped edges).
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $keepKeys
     * @param  callable(object): string  $keyFor
     */
    private function deleteStaleRows($query, array $keepKeys, callable $keyFor): void
    {
        $query->where('flow', self::FLOW)->get()
            ->reject(fn (object $row): bool => in_array($keyFor($row), $keepKeys, true))
            ->each(fn (object $row) => $row->delete());
    }
}
