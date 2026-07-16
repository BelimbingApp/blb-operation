<?php

namespace App\Modules\Operation\IT\Livewire\Tickets;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\Operation\IT\Livewire\Concerns\PresentsTicketBadges;
use App\Modules\Operation\IT\Models\Ticket;
use App\Modules\Operation\IT\Services\TicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    use InteractsWithNotifications, PresentsTicketBadges;

    public Ticket $ticket;

    /**
     * Composer draft: posted as a comment, or carried along by a
     * transition button so the "why" lands in the same timeline entry.
     */
    public string $comment = '';

    public string $assigneeSelection = '';

    public function mount(Ticket $ticket): void
    {
        abort_unless($ticket->company_id === Auth::user()?->getCompanyId(), 404);

        $this->ticket = $ticket->load('reporter', 'assignee');
        $this->assigneeSelection = $this->ticket->assignee_id !== null ? (string) $this->ticket->assignee_id : '';
    }

    /**
     * Post the composer text as a timeline comment.
     */
    public function postComment(TicketService $ticketService): void
    {
        $this->validate(
            ['comment' => ['required', 'string', 'max:5000']],
            [],
            ['comment' => __('comment')],
        );

        $ticketService->postComment($this->ticket, Actor::forUser(Auth::user()), trim($this->comment));

        $this->comment = '';
        $this->notify(__('Comment posted.'));
    }

    /**
     * Transition the ticket to a new status via the workflow engine,
     * carrying the composer text as the transition comment.
     */
    public function transitionTo(string $toCode, TicketService $ticketService): void
    {
        $result = $ticketService->transition(
            $this->ticket,
            Actor::forUser(Auth::user()),
            $toCode,
            trim($this->comment) !== '' ? trim($this->comment) : null,
        );

        if ($result->success) {
            $this->comment = '';
            $this->refreshTicket();
            $this->notify(__('Ticket moved to :status.', ['status' => $this->statusLabel($this->ticket->status)]));
        } else {
            $this->notifyError($result->reason ?? __('Transition failed.'));
        }
    }

    /**
     * Assign or reassign the ticket when the assignee combobox commits.
     */
    public function updatedAssigneeSelection(): void
    {
        $ticketService = app(TicketService::class);
        $selection = $this->assigneeSelection !== '' ? (int) $this->assigneeSelection : null;

        if ($selection === null || $selection === $this->ticket->assignee_id) {
            $this->assigneeSelection = $this->ticket->assignee_id !== null ? (string) $this->ticket->assignee_id : '';

            return;
        }

        if (! $this->allowed('operations.it.ticket.assign')) {
            $this->resetAssigneeSelection();
            $this->notifyError(__('You do not have permission to assign tickets.'));

            return;
        }

        $assignee = Employee::query()
            ->where('company_id', $this->ticket->company_id)
            ->where('status', 'active')
            ->find($selection);

        if ($assignee === null) {
            $this->resetAssigneeSelection();
            $this->notifyError(__('That employee cannot be assigned.'));

            return;
        }

        $result = $ticketService->assign($this->ticket, Actor::forUser(Auth::user()), $assignee);

        if ($result->success) {
            $this->refreshTicket();
            $this->notify(__('Assigned to :name.', ['name' => $assignee->displayName()]));
        } else {
            $this->resetAssigneeSelection();
            $this->notifyError($result->reason ?? __('Assignment failed.'));
        }
    }

    /**
     * Save an edit-in-place fact (priority, category, location).
     */
    public function saveField(string $field, ?string $value, TicketService $ticketService): void
    {
        if (! $this->allowed('operations.it.ticket.update')) {
            $this->notifyError(__('You do not have permission to update tickets.'));

            return;
        }

        $rules = [
            'priority' => ['required', Rule::in(array_keys(config('it.priorities')))],
            'category' => ['nullable', Rule::in(array_keys(config('it.categories')))],
            'location' => ['nullable', 'string', 'max:255'],
        ];

        if (! array_key_exists($field, $rules)) {
            return;
        }

        $validated = validator(
            [$field => $value !== '' ? $value : null],
            [$field => $rules[$field]],
        )->validate();

        $ticketService->updateDetails($this->ticket, $validated);
        $this->refreshTicket();
        $this->notify(__('Saved.'));
    }

    public function render(): View
    {
        return view('operation-it::livewire.it.tickets.show', [
            'timeline' => $this->timelineWithActorNames(),
            'availableTransitions' => $this->actionableTransitions(),
            'assigneeOptions' => $this->assigneeOptions(),
            'canUpdate' => $this->allowed('operations.it.ticket.update'),
            'canAssign' => $this->allowed('operations.it.ticket.assign'),
        ]);
    }

    private function refreshTicket(): void
    {
        $this->ticket->refresh()->load('reporter', 'assignee');
        $this->assigneeSelection = $this->ticket->assignee_id !== null ? (string) $this->ticket->assignee_id : '';
    }

    private function resetAssigneeSelection(): void
    {
        $this->assigneeSelection = $this->ticket->assignee_id !== null ? (string) $this->ticket->assignee_id : '';
    }

    /**
     * Timeline entries with actor display names resolved for the view.
     *
     * @return EloquentCollection<int, StatusHistory>
     */
    private function timelineWithActorNames(): EloquentCollection
    {
        $timeline = $this->ticket->statusTimeline();

        $names = User::query()
            ->whereIn('id', $timeline->pluck('actor_id')->filter()->unique())
            ->pluck('name', 'id');

        return $timeline->each(function ($entry) use ($names): void {
            $entry->setAttribute('actorName', $names[$entry->actor_id] ?? null);
        });
    }

    /**
     * Transitions the current user can actually take from here.
     *
     * The open → assigned edge is excluded: assignment goes through the
     * Assignee field so a ticket never enters `assigned` without an owner.
     *
     * @return EloquentCollection<int, StatusTransition>
     */
    private function actionableTransitions(): EloquentCollection
    {
        return $this->ticket->availableTransitions()
            ->reject(fn (StatusTransition $transition): bool => $transition->from_code === 'open' && $transition->to_code === 'assigned')
            ->filter(fn (StatusTransition $transition): bool => $transition->capability === null || $this->allowed($transition->capability))
            ->values();
    }

    /**
     * Active employees of the ticket's company, for the assignee picker.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function assigneeOptions(): array
    {
        return Employee::query()
            ->where('company_id', $this->ticket->company_id)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get()
            ->map(fn (Employee $employee): array => [
                'value' => (string) $employee->id,
                'label' => $employee->displayName(),
            ])
            ->all();
    }

    private function allowed(string $capability): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), $capability)
            ->allowed;
    }
}
