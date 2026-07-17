<?php

namespace App\Modules\Operation\IT\Workflow;

use App\Base\Workflow\Contracts\TransitionAction;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * Persist the assignee within the workflow engine transaction.
 */
class AssignTicket implements TransitionAction
{
    public function execute(Model $model, StatusTransition $transition, TransitionContext $context): void
    {
        $assigneeId = $context->metadata['assignee_id'] ?? null;

        if (is_int($assigneeId)) {
            $model->setAttribute('assignee_id', $assigneeId);
            $model->save();
        }
    }
}
