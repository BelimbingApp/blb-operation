<?php

namespace App\Modules\Operation\IT\Workflow;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\Contracts\TransitionGuard;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * Deny entering `assigned` unless the ticket actually has an assignee.
 *
 * `TicketService::assign()` sets the assignee before transitioning, so the
 * blessed path always passes; raw transition attempts (buttons, tools)
 * fail honestly instead of producing an assigned ticket nobody owns.
 */
class RequiresAssigneeGuard implements TransitionGuard
{
    public function evaluate(Model $model, StatusTransition $transition, Actor $actor): GuardResult
    {
        if ($model->getAttribute('assignee_id') !== null) {
            return GuardResult::allow();
        }

        return GuardResult::deny(__('Pick an assignee first — use the Assignee field.'));
    }
}
