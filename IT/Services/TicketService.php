<?php

namespace App\Modules\Operation\IT\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionResult;
use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\Operation\IT\Exceptions\TicketMutationDenied;
use App\Modules\Operation\IT\Models\Ticket;
use App\Modules\Operation\IT\Notifications\TicketCommentPosted;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Domain service for IT ticket operations.
 *
 * Centralizes ticket creation and comment posting so that both the
 * Livewire UI and AI tools share the same mutation logic.
 */
class TicketService
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * Create a new IT ticket with initial status history.
     *
     * @param  Actor  $actor  The principal performing the action (audit/auth context)
     * @param  Employee  $reporter  The employee reporting the ticket (business ownership)
     * @param  array{title: string, priority: string, category?: string|null, description?: string|null, location?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function create(Actor $actor, Employee $reporter, array $data): Ticket
    {
        if ($actor->companyId === null || $actor->companyId !== (int) $reporter->company_id) {
            throw new TicketMutationDenied(__('The reporter must belong to the acting company.'));
        }

        return DB::transaction(function () use ($actor, $reporter, $data): Ticket {
            $ticket = Ticket::query()->create([
                'company_id' => $reporter->company_id,
                'reporter_id' => $reporter->id,
                'status' => 'open',
                'title' => $data['title'],
                'priority' => $data['priority'],
                'category' => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            StatusHistory::query()->create([
                'flow' => 'it_ticket',
                'flow_id' => $ticket->id,
                'status' => 'open',
                'actor_id' => $actor->id,
                'actor_type' => $actor->type->value,
                'actor_role' => $actor->attributes['role'] ?? null,
                'actor_department' => $actor->attributes['department'] ?? null,
                'actor_company' => $actor->attributes['company'] ?? null,
                'comment' => $data['description'] ?? null,
                'comment_tag' => 'report',
                'metadata' => ['priority' => $data['priority']],
                'transitioned_at' => Carbon::now(),
            ]);

            return $ticket;
        });
    }

    /**
     * Post a comment to a ticket's status history without changing status.
     *
     * @param  Ticket  $ticket  The ticket to comment on
     * @param  Actor  $actor  The principal posting the comment
     * @param  string  $comment  The comment text
     * @param  string|null  $commentTag  Comment category (e.g., agent_progress, agent_question)
     * @param  array<string, mixed>|null  $metadata  Additional context
     */
    public function postComment(
        Ticket $ticket,
        Actor $actor,
        string $comment,
        ?string $commentTag = null,
        ?array $metadata = null,
    ): StatusHistory {
        $this->ensureActorCompany($ticket, $actor);

        $history = StatusHistory::query()->create([
            'flow' => 'it_ticket',
            'flow_id' => $ticket->id,
            'status' => $ticket->status,
            'actor_id' => $actor->id,
            'actor_type' => $actor->type->value,
            'actor_role' => $actor->attributes['role'] ?? null,
            'actor_department' => $actor->attributes['department'] ?? null,
            'actor_company' => $actor->attributes['company'] ?? null,
            'comment' => $comment,
            'comment_tag' => $commentTag,
            'metadata' => $metadata,
            'transitioned_at' => Carbon::now(),
        ]);

        $this->notifyCommentStakeholders($ticket, $actor, $history);

        return $history;
    }

    /**
     * Assign (or reassign) a ticket to an employee.
     *
     * On an `open` ticket this drives the open → assigned transition so the
     * workflow history and notifications fire; on an already-running ticket
     * it swaps the assignee and records the handover as a timeline comment.
     */
    public function assign(Ticket $ticket, Actor $actor, Employee $assignee): TransitionResult
    {
        if (! $this->actorSharesCompany($ticket, $actor)) {
            return TransitionResult::failure(__('Ticket mutations must stay within the acting company.'));
        }

        if (! $this->authorizationService->can($actor, 'operations.it.ticket.assign')->allowed) {
            return TransitionResult::failure(__('You do not have permission to assign tickets.'));
        }

        if ((int) $assignee->company_id !== (int) $ticket->company_id || $assignee->status !== 'active') {
            return TransitionResult::failure(__('Pick an active assignee from this company.'));
        }

        if (in_array($ticket->status, Ticket::DONE_STATUSES, true)) {
            return TransitionResult::failure(__('Resolved or closed tickets cannot be assigned. Reopen the ticket first.'));
        }

        if ($ticket->assignee_id === $assignee->id) {
            return TransitionResult::failure(__(':name already owns this ticket.', ['name' => $assignee->displayName()]));
        }

        if ($ticket->status === 'open') {
            return $this->transition(
                $ticket,
                $actor,
                'assigned',
                __('Assigned to :name', ['name' => $assignee->displayName()]),
                'assignment',
                ['assignee_id' => $assignee->id],
            );
        }

        return DB::transaction(function () use ($ticket, $actor, $assignee): TransitionResult {
            $ticket->update(['assignee_id' => $assignee->id]);
            $history = $this->postComment(
                $ticket,
                $actor,
                __('Reassigned to :name', ['name' => $assignee->displayName()]),
                'assignment',
            );

            return TransitionResult::success($history);
        });
    }

    /**
     * Update the editable facts of a ticket.
     *
     * Changes are captured by the model audit trail; the workflow timeline
     * stays reserved for status changes and conversation.
     *
     * @param  array{title?: string, description?: string|null, priority?: string, category?: string|null, location?: string|null}  $data
     */
    public function updateDetails(Ticket $ticket, Actor $actor, array $data): Ticket
    {
        $this->ensureActorCompany($ticket, $actor);
        $ticket->fill(Arr::only($data, ['title', 'description', 'priority', 'category', 'location']));
        $ticket->save();

        return $ticket;
    }

    /**
     * Notify ticket stakeholders (reporter, assignee) about a new comment,
     * excluding whoever wrote it.
     */
    private function notifyCommentStakeholders(Ticket $ticket, Actor $actor, StatusHistory $history): void
    {
        $ticket->loadMissing('reporter.user', 'assignee.user');

        $recipients = collect([$ticket->reporter?->user, $ticket->assignee?->user])
            ->filter()
            ->filter(fn (User $user): bool => (int) $user->company_id === (int) $ticket->company_id)
            ->unique(fn (User $user): int => $user->id);

        if ($actor->isUser()) {
            $recipients = $recipients->reject(fn (User $user): bool => $user->id === $actor->id);
        }

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new TicketCommentPosted($ticket, $history, $this->actorDisplayName($actor));

        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    /**
     * Human name for the acting principal: agents are employees, users are users.
     */
    private function actorDisplayName(Actor $actor): string
    {
        if ($actor->isAgent()) {
            return Employee::query()
                ->where('company_id', $actor->companyId)
                ->find($actor->id)?->displayName() ?? __('Agent');
        }

        return User::query()
            ->where('company_id', $actor->companyId)
            ->find($actor->id)?->name ?? __('User #:id', ['id' => $actor->id]);
    }

    /**
     * Transition a ticket to a new status via the workflow engine.
     *
     * @param  Ticket  $ticket  The ticket to transition
     * @param  Actor  $actor  The principal triggering the transition
     * @param  string  $toCode  Target status code
     * @param  string|null  $comment  Optional transition comment
     * @param  string|null  $commentTag  Comment category
     * @param  array<string, mixed>|null  $metadata  Process-specific transition input
     */
    public function transition(
        Ticket $ticket,
        Actor $actor,
        string $toCode,
        ?string $comment = null,
        ?string $commentTag = null,
        ?array $metadata = null,
    ): TransitionResult {
        if (! $this->actorSharesCompany($ticket, $actor)) {
            return TransitionResult::failure(__('Ticket mutations must stay within the acting company.'));
        }

        $context = new TransitionContext(
            actor: $actor,
            comment: $comment,
            commentTag: $commentTag,
            metadata: $metadata,
        );

        return $ticket->transitionTo($toCode, $context);
    }

    private function ensureActorCompany(Ticket $ticket, Actor $actor): void
    {
        if (! $this->actorSharesCompany($ticket, $actor)) {
            throw new TicketMutationDenied(__('Ticket mutations must stay within the acting company.'));
        }
    }

    private function actorSharesCompany(Ticket $ticket, Actor $actor): bool
    {
        return $actor->companyId !== null && $actor->companyId === (int) $ticket->company_id;
    }
}
