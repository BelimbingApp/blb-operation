<?php
/** @var \App\Modules\Operation\IT\Livewire\Widgets\TicketQueue $this */
?>

<div>
    <x-ui.card>
        <x-ui.widget-header :title="__('IT Tickets')" :href="route('it.tickets.index')" :open-label="__('Open ticket queue')" class="mb-0" />

        <x-ui.stat-strip class="mt-2">
            <x-ui.stat :label="__('Open')">{{ $stats['open'] }}</x-ui.stat>
            <x-ui.stat :label="__('Mine')">{{ $stats['mine'] }}</x-ui.stat>
            <x-ui.stat :label="__('Waiting')">{{ $stats['waiting'] }}</x-ui.stat>
        </x-ui.stat-strip>

        @if($attention->isEmpty())
            <p class="mt-3 text-xs text-muted">{{ __('Nothing needs attention — the queue is clear.') }}</p>
        @else
            <ul class="mt-3 divide-y divide-border-default border-t border-border-default" aria-label="{{ __('Tickets needing attention') }}">
                @foreach($attention as $ticket)
                    <li wire:key="attention-{{ $ticket->id }}" class="flex items-center gap-2 py-1.5">
                        <x-ui.badge :variant="$this->priorityVariant($ticket->priority)">{{ $this->priorityLabel($ticket->priority) }}</x-ui.badge>
                        <x-ui.link
                            href="{{ route('it.tickets.show', $ticket) }}"
                            class="min-w-0 flex-1 truncate text-sm"
                            title="{{ $ticket->title }}"
                        >{{ $ticket->title }}</x-ui.link>
                        <span class="shrink-0 text-xs text-muted tabular-nums" title="{{ $ticket->status === 'blocked' ? __('Blocked — waiting on input') : __('Unassigned') }}">
                            @if($ticket->status === 'blocked')
                                {{ $this->statusLabel('blocked') }}
                            @else
                                <x-ui.datetime :value="$ticket->created_at" />
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
