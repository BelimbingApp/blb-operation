<?php

namespace App\Modules\Operation\IT\Workflow;

use App\Base\Workflow\Contracts\TransitionAction;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * Reset ownership when a ticket goes back to the open queue:
 * clear the assignee (open means "nobody owns this yet") and the
 * resolution stamp (a reopened ticket is no longer resolved).
 */
class ReturnTicketToQueue implements TransitionAction
{
    public function execute(Model $model, StatusTransition $transition, TransitionContext $context): void
    {
        $model->setAttribute('assignee_id', null);
        $model->setAttribute('resolved_at', null);
        $model->save();
    }
}
