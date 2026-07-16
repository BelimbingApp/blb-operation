<?php
/** @var \App\Modules\Operation\IT\Livewire\Widgets\TicketQueue $this */
?>

<div>
    <x-ui.card>
        <x-ui.widget-header :title="__('IT Tickets')" :href="route('it.tickets.index')" :open-label="__('Open ticket queue')" class="mb-0" />

        <div class="mt-2 grid grid-cols-3 gap-2">
            <div>
                <p class="text-2xl font-medium tracking-tight text-ink tabular-nums">{{ $stats['open'] }}</p>
                <p class="text-xs text-muted">{{ __('open') }}</p>
            </div>
            <div>
                <p class="text-2xl font-medium tracking-tight text-ink tabular-nums">{{ $stats['mine'] }}</p>
                <p class="text-xs text-muted">{{ __('mine') }}</p>
            </div>
            <div>
                <p class="text-2xl font-medium tracking-tight text-ink tabular-nums">{{ $stats['waiting'] }}</p>
                <p class="text-xs text-muted">{{ __('waiting') }}</p>
            </div>
        </div>

        @if($attention->isEmpty())
            <p class="mt-3 text-xs text-muted">{{ __('Nothing needs attention — the queue is clear.') }}</p>
        @else
            <ul class="mt-3 divide-y divide-border-default border-t border-border-default" aria-label="{{ __('Tickets needing attention') }}">
                @foreach($attention as $ticket)
                    <li wire:key="attention-{{ $ticket->id }}" class="flex items-center gap-2 py-1.5">
                        <x-ui.badge :variant="$this->priorityVariant($ticket->priority)">{{ $this->priorityLabel($ticket->priority) }}</x-ui.badge>
                        <a
                            href="{{ route('it.tickets.show', $ticket) }}"
                            wire:navigate
                            class="min-w-0 flex-1 truncate text-sm text-ink hover:text-accent"
                            title="{{ $ticket->title }}"
                        >{{ $ticket->title }}</a>
                        <span class="shrink-0 text-xs text-muted tabular-nums" title="{{ $ticket->status === 'blocked' ? __('Blocked — waiting on input') : __('Unassigned') }}">
                            {{ $ticket->status === 'blocked' ? $this->statusLabel('blocked') : $ticket->created_at?->diffForHumans(['short' => true, 'parts' => 1]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
