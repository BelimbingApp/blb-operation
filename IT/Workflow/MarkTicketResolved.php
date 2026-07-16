<?php

namespace App\Modules\Operation\IT\Workflow;

use App\Base\Workflow\Contracts\TransitionAction;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stamp `resolved_at` when a ticket enters the resolved status.
 */
class MarkTicketResolved implements TransitionAction
{
    public function execute(Model $model, StatusTransition $transition, TransitionContext $context): void
    {
        $model->setAttribute('resolved_at', Carbon::now());
        $model->save();
    }
}
