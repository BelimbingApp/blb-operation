<?php

namespace App\Modules\Operation\IT\Services;

use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Core\AI\Contracts\AgentTaskContextContributor;
use App\Modules\Core\AI\DTO\PromptSection;
use App\Modules\Core\AI\Enums\PromptSectionType;
use App\Modules\Operation\IT\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributes ticket context to delegated agent task prompts.
 *
 * Registered under the AgentTaskContextContributor container tag so
 * Core/AI can build ticket-aware prompts without importing IT classes.
 */
class TicketContextContributor implements AgentTaskContextContributor
{
    /**
     * Maximum number of recent timeline entries to include in ticket context.
     */
    private const MAX_TIMELINE_ENTRIES = 10;

    public function supports(Model $entity): bool
    {
        return $entity instanceof Ticket;
    }

    public function section(Model $entity): PromptSection
    {
        /** @var Ticket $ticket */
        $ticket = $entity;

        $context = [
            'ticket_id' => $ticket->id,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'category' => $ticket->category,
            'description' => $ticket->description,
            'reporter' => $ticket->reporter?->displayName(),
            'assignee' => $ticket->assignee?->displayName(),
        ];

        $timeline = $this->recentTimeline($ticket);

        if ($timeline !== []) {
            $context['recent_timeline'] = $timeline;
        }

        $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new PromptSection(
            label: 'ticket_context',
            content: "Ticket context (JSON):\n".$encoded,
            type: PromptSectionType::Operational,
            order: 0,
            source: 'agent_task_ticket_context',
        );
    }

    /**
     * @return list<array{status: string, comment: string|null, comment_tag: string|null, actor_id: int, transitioned_at: string}>
     */
    private function recentTimeline(Ticket $ticket): array
    {
        return StatusHistory::query()
            ->where('flow', 'it_ticket')
            ->where('flow_id', $ticket->id)
            ->orderByDesc('transitioned_at')
            ->limit(self::MAX_TIMELINE_ENTRIES)
            ->get()
            ->map(fn (StatusHistory $entry): array => [
                'status' => $entry->status,
                'comment' => $entry->comment,
                'comment_tag' => $entry->comment_tag,
                'actor_id' => $entry->actor_id,
                'transitioned_at' => $entry->transitioned_at?->toIso8601String() ?? '',
            ])
            ->reverse()
            ->values()
            ->all();
    }
}
