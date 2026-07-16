<?php
/** @var \App\Modules\Operation\IT\Livewire\Tickets\Board $this */
?>

<div>
    <x-slot name="title">{{ __('Ticket Board') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Ticket Board')" :subtitle="__('Drag tickets as work moves — only real workflow steps are allowed')">
            <x-slot name="actions">
                <x-ui.link href="{{ route('it.tickets.index') }}" wire:navigate>
                    {{ __('List') }}
                </x-ui.link>
                <x-ui.button variant="primary" as="a" href="{{ route('it.tickets.create') }}" wire:navigate>
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('New Ticket') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.checkbox
                id="board-mine-only"
                wire:model.live="mineOnly"
                :label="__('My tickets only')"
            />
            <div class="w-44">
                <x-ui.select id="board-priority-filter" wire:model.live="priorityFilter" aria-label="{{ __('Filter by priority') }}">
                    <option value="">{{ __('All Priorities') }}</option>
                    @foreach(config('it.priorities') as $code => $label)
                        <option value="{{ $code }}">{{ __($label) }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <p class="text-xs text-muted">{{ __('Done shows the last :days days.', ['days' => 14]) }}</p>
        </div>

        <div
            x-data="{
                dragging: null,
                overColumn: null,
                chooser: null,
                assigneePick: '',
                transitions: @js($transitionMap),
                startDrag(id, status, column) {
                    this.dragging = { id, status, column };
                },
                endDrag() {
                    this.dragging = null;
                    this.overColumn = null;
                },
                candidatesFor(column) {
                    if (! this.dragging || this.dragging.column === column) return [];
                    return (this.transitions[this.dragging.status] ?? []).filter(t => t.column === column);
                },
                canDrop(column) {
                    return this.candidatesFor(column).length > 0;
                },
                drop(column, event) {
                    const d = this.dragging;
                    const candidates = this.candidatesFor(column);
                    this.overColumn = null;
                    this.dragging = null;

                    if (! d || d.column === column) return;

                    if (candidates.length === 0) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: {
                            message: @js(__('That move is not a step in the ticket workflow.')),
                            variant: 'warning',
                        }}));
                        return;
                    }

                    if (candidates.length === 1 && ! candidates[0].needsAssignee) {
                        $wire.moveTicket(d.id, candidates[0].to);
                        return;
                    }

                    this.assigneePick = '';
                    this.chooser = {
                        ticketId: d.id,
                        options: candidates,
                        x: Math.min(event.clientX, window.innerWidth - 300),
                        y: Math.min(event.clientY, window.innerHeight - 220),
                    };
                },
                choose(option) {
                    if (option.needsAssignee) {
                        this.chooser.options = [option];
                        return;
                    }
                    $wire.moveTicket(this.chooser.ticketId, option.to);
                    this.chooser = null;
                },
                confirmAssign() {
                    if (! this.assigneePick) return;
                    $wire.assignTicket(this.chooser.ticketId, parseInt(this.assigneePick, 10));
                    this.chooser = null;
                },
            }"
            @keydown.escape.window="chooser = null"
            class="relative"
        >
            <div class="overflow-x-auto pb-2" wire:loading.class="opacity-60" wire:target="moveTicket, assignTicket, mineOnly, priorityFilter">
                <div class="flex items-start gap-3" style="min-width: max(100%, 72rem)">
                    @foreach($columns as $column)
                        <section
                            wire:key="column-{{ $column->code }}"
                            class="flex-1 min-w-[14rem] rounded-lg border bg-surface-subtle/50 transition-colors"
                            :class="overColumn === '{{ $column->code }}'
                                ? (canDrop('{{ $column->code }}') ? 'border-accent bg-surface-subtle' : 'border-status-danger-border')
                                : 'border-border-default'"
                            @dragover.prevent="overColumn = '{{ $column->code }}'"
                            @dragleave="if (overColumn === '{{ $column->code }}') overColumn = null"
                            @drop.prevent="drop('{{ $column->code }}', $event)"
                            aria-label="{{ __($column->label) }}"
                        >
                            @php
                                $columnStatuses = $statusColumn->filter(fn (string $code): bool => $code === $column->code);
                            @endphp
                            <header class="flex items-center justify-between px-3 py-2">
                                <h2 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __($column->label) }}</h2>
                                <span class="text-xs text-muted tabular-nums">{{ $ticketsByColumn->get($column->code)?->count() ?? 0 }}</span>
                            </header>

                            <div class="px-2 pb-2 space-y-2 min-h-[6rem]">
                                @foreach($ticketsByColumn->get($column->code) ?? [] as $ticket)
                                    <article
                                        wire:key="board-ticket-{{ $ticket->id }}"
                                        draggable="true"
                                        @dragstart="startDrag({{ $ticket->id }}, '{{ $ticket->status }}', '{{ $column->code }}')"
                                        @dragend="endDrag()"
                                        class="rounded-md border border-border-default bg-surface-card p-2.5 shadow-sm cursor-grab active:cursor-grabbing"
                                        :class="dragging?.id === {{ $ticket->id }} ? 'opacity-50' : ''"
                                    >
                                        <a
                                            href="{{ route('it.tickets.show', $ticket) }}"
                                            wire:navigate
                                            class="block text-sm font-medium text-ink hover:text-accent leading-snug"
                                        >{{ $ticket->title }}</a>

                                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                            <x-ui.badge :variant="$this->priorityVariant($ticket->priority)">{{ $this->priorityLabel($ticket->priority) }}</x-ui.badge>
                                            @if($columnStatuses->count() > 1)
                                                <x-ui.badge :variant="$this->statusVariant($ticket->status)">{{ $this->statusLabel($ticket->status) }}</x-ui.badge>
                                            @endif
                                        </div>

                                        <div class="mt-2 flex items-center justify-between text-xs text-muted">
                                            <span class="truncate">{{ $ticket->assignee?->displayName() ?? __('Unassigned') }}</span>
                                            <span class="shrink-0 tabular-nums" title="{{ $ticket->created_at?->format('Y-m-d H:i:s') }}">{{ $ticket->created_at?->diffForHumans(['short' => true, 'parts' => 1]) }}</span>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>

            {{-- Drop chooser: ambiguous column (or assignment) needs one more answer --}}
            <div
                x-cloak
                x-show="chooser !== null"
                x-transition.opacity.duration.100ms
                @click.outside="chooser = null"
                class="fixed z-50 w-64 rounded-lg border border-border-default bg-surface-card p-2 shadow-lg"
                :style="chooser ? `left: ${chooser.x}px; top: ${chooser.y}px` : ''"
                role="menu"
                aria-label="{{ __('Choose a workflow step') }}"
            >
                <template x-for="option in (chooser?.options ?? [])" :key="option.to">
                    <div>
                        <template x-if="! option.needsAssignee">
                            <button
                                type="button"
                                class="w-full rounded px-2 py-1.5 text-left text-sm text-ink hover:bg-surface-subtle"
                                role="menuitem"
                                @click="choose(option)"
                                x-text="option.label"
                            ></button>
                        </template>
                        <template x-if="option.needsAssignee">
                            <div class="space-y-2 p-1">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted" x-text="option.label"></p>
                                <select
                                    x-model="assigneePick"
                                    class="w-full pl-input-x pr-8 py-input-y text-sm border border-border-input rounded-2xl bg-surface-card text-ink focus:outline-none focus:ring-2 focus:ring-accent"
                                    aria-label="{{ __('Assign to') }}"
                                >
                                    <option value="">{{ __('Assign to...') }}</option>
                                    @foreach($assigneeOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <x-ui.button variant="primary" size="sm" x-bind:disabled="! assigneePick" @click="confirmAssign()">
                                    {{ __('Assign') }}
                                </x-ui.button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
