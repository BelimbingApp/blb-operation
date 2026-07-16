<?php

namespace App\Modules\Operation\IT\Notifications;

use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Operation\IT\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to ticket stakeholders (reporter, assignee) when someone posts
 * a comment on the ticket timeline without changing status.
 */
class TicketCommentPosted extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly StatusHistory $history,
        public readonly string $authorName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'comment',
            'flow' => Ticket::FLOW,
            'model_type' => Ticket::class,
            'model_id' => $this->ticket->id,
            'title' => $this->ticket->workflowNotificationTitle(),
            'url' => $this->ticket->workflowNotificationUrl(),
            'body' => sprintf('%s — %s', $this->authorName, $this->history->comment),
            'comment_tag' => $this->history->comment_tag,
            'actor_id' => $this->history->actor_id,
        ];
    }
}
