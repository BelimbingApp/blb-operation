<?php

namespace App\Modules\Operation\IT\Livewire\Concerns;

use App\Base\Workflow\Models\StatusConfig;
use App\Modules\Operation\IT\Models\Ticket;

/**
 * Shared presentation helpers for ticket Livewire pages: badge variants
 * and label lookups sourced from the workflow config and module config.
 */
trait PresentsTicketBadges
{
    public function priorityVariant(string $priority): string
    {
        return match ($priority) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            default => 'default',
        };
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'open' => 'info',
            'assigned' => 'accent',
            'in_progress' => 'warning',
            'blocked' => 'danger',
            'awaiting_parts' => 'warning',
            'review' => 'accent',
            'resolved' => 'success',
            'closed' => 'default',
            default => 'default',
        };
    }

    public function priorityLabel(string $priority): string
    {
        return __(config('it.priorities.'.$priority, ucfirst($priority)));
    }

    public function categoryLabel(?string $category): string
    {
        if ($category === null || $category === '') {
            return '—';
        }

        return __(config('it.categories.'.$category, ucfirst($category)));
    }

    public function statusLabel(string $status): string
    {
        return $this->ticketStatuses()[$status] ?? str_replace('_', ' ', ucfirst($status));
    }

    /**
     * Active status codes => labels for the it_ticket flow, in graph order.
     *
     * @return array<string, string>
     */
    public function ticketStatuses(): array
    {
        return once(fn (): array => StatusConfig::query()
            ->forFlow(Ticket::FLOW)
            ->active()
            ->orderBy('position')
            ->pluck('label', 'code')
            ->all());
    }
}
