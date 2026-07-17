<?php

namespace App\Modules\Operation\IT\Workflow;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\Contracts\ContextualTransitionGuard;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Deny entering `assigned` unless the ticket actually has an assignee.
 *
 * The contextual path validates a proposed assignee before the transition
 * action persists it in the same transaction as the status change.
 */
class RequiresAssigneeGuard implements ContextualTransitionGuard
{
    public function evaluate(Model $model, StatusTransition $transition, Actor $actor): GuardResult
    {
        return $this->evaluateAssignee($model, $model->getAttribute('assignee_id'));
    }

    public function evaluateWithContext(
        Model $model,
        StatusTransition $transition,
        Actor $actor,
        TransitionContext $context,
    ): GuardResult {
        return $this->evaluateAssignee(
            $model,
            $context->metadata['assignee_id'] ?? $model->getAttribute('assignee_id'),
        );
    }

    private function evaluateAssignee(Model $model, mixed $assigneeId): GuardResult
    {
        if (is_int($assigneeId) && Employee::query()
            ->whereKey($assigneeId)
            ->where('company_id', $model->getAttribute('company_id'))
            ->where('status', 'active')
            ->exists()) {
            return GuardResult::allow();
        }

        return GuardResult::deny(__('Pick an active assignee from this company first.'));
    }
}
