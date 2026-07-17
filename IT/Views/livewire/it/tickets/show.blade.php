<?php

use App\Modules\Operation\IT\Livewire\Tickets\Show;

/** @var Show $this */
?>

<div>
    <x-slot name="title">{{ __('Ticket #:id', ['id' => $ticket->id]) }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$ticket->title" :subtitle="__('Ticket #:id', ['id' => $ticket->id])">
            <x-slot name="actions">
                <x-ui.record-history
                    :title="__('History for ticket #:id', ['id' => $ticket->id])"
                    :subjects="[['name' => 'ticket', 'id' => $ticket->id]]"
                    :auditable-type="$ticket->getMorphClass()"
                    :auditable-id="$ticket->id"
                    source-capability="operations.it.ticket.view"
                />
                <x-ui.link href="{{ route('it.tickets.index') }}" wire:navigate>
                    {{ __('Back to queue') }}
                </x-ui.link>
            </x-slot>
        </x-ui.page-header>

        <x-ui.session-flash />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Conversation: what happened, what happens next --}}
            <div class="lg:col-span-2 space-y-6">
                @if($ticket->description)
                    <x-ui.card>
                        <p class="text-[11px] font-semibold text-muted uppercase tracking-wider mb-1">{{ __('Description') }}</p>
                        <p class="text-sm text-ink whitespace-pre-wrap">{{ $ticket->description }}</p>
                    </x-ui.card>
                @endif

                <x-ui.card>
                    <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Activity') }}</h2>
                    <x-workflow.status-timeline :entries="$timeline" />

                    @if($canUpdate)
                    <div class="mt-6 pt-4 border-t border-border-default space-y-3">
                        <x-ui.textarea
                            id="ticket-comment"
                            wire:model="comment"
                            :label="__('Add to the conversation')"
                            rows="2"
                            placeholder="{{ __('Share progress, ask a question, or explain a status change...') }}"
                            :error="$errors->first('comment')"
                        />

                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.button
                                variant="primary"
                                wire:click="postComment"
                                wire:loading.attr="disabled"
                                wire:target="postComment"
                            >
                                <x-icon name="heroicon-o-chat-bubble-left-ellipsis" class="w-4 h-4" />
                                {{ __('Comment') }}
                            </x-ui.button>

                            @if($availableTransitions->isNotEmpty())
                                <span class="text-xs text-muted px-1" aria-hidden="true">{{ __('or move it:') }}</span>
                                @foreach($availableTransitions as $transition)
                                    @if($transition->to_code === 'closed')
                                        <x-ui.button
                                            variant="outline"
                                            wire:key="transition-{{ $transition->to_code }}"
                                            wire:click="transitionTo('{{ $transition->to_code }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="transitionTo"
                                            wire:confirm="{{ __('Close this ticket? Closed tickets cannot be reopened.') }}"
                                        >
                                            {{ $transition->resolveLabel() }}
                                        </x-ui.button>
                                    @else
                                        <x-ui.button
                                            variant="outline"
                                            wire:key="transition-{{ $transition->to_code }}"
                                            wire:click="transitionTo('{{ $transition->to_code }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="transitionTo"
                                        >
                                            {{ $transition->resolveLabel() }}
                                        </x-ui.button>
                                    @endif
                                @endforeach
                            @endif
                        </div>
                        <p class="text-xs text-muted">{{ __('Moving the ticket carries your comment along, so the reason lands in the timeline.') }}</p>
                    </div>
                    @endif
                </x-ui.card>
            </div>

            {{-- Facts rail --}}
            <div class="space-y-6">
                <x-ui.card>
                    <div class="space-y-4">
                        <dl>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                            <dd class="mt-0.5">
                                <x-ui.badge :variant="$this->statusVariant($ticket->status)">{{ $this->statusLabel($ticket->status) }}</x-ui.badge>
                            </dd>
                        </dl>

                        @if($canUpdate)
                            <x-ui.edit-in-place.select
                                id="ticket-priority"
                                :label="__('Priority')"
                                :value="$ticket->priority"
                                field="priority"
                            >
                                <x-slot name="read">
                                    <x-ui.badge :variant="$this->priorityVariant($ticket->priority)">{{ $this->priorityLabel($ticket->priority) }}</x-ui.badge>
                                </x-slot>
                                @foreach(config('it.priorities') as $code => $label)
                                    <option value="{{ $code }}">{{ __($label) }}</option>
                                @endforeach
                            </x-ui.edit-in-place.select>
                        @else
                            <dl>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Priority') }}</dt>
                                <dd class="mt-0.5">
                                    <x-ui.badge :variant="$this->priorityVariant($ticket->priority)">{{ $this->priorityLabel($ticket->priority) }}</x-ui.badge>
                                </dd>
                            </dl>
                        @endif

                        @if($canAssign && $ticket->status !== 'closed')
                            <x-ui.edit-in-place.combobox
                                id="ticket-assignee"
                                :label="__('Assignee')"
                                wire:model.live="assigneeSelection"
                                :value="$assigneeSelection"
                                :display="$ticket->assignee?->displayName() ?? __('Unassigned')"
                                :options="$assigneeOptions"
                                :placeholder="__('Search people...')"
                            />
                        @else
                            <dl>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Assignee') }}</dt>
                                <dd class="mt-0.5 text-sm {{ $ticket->assignee ? 'text-ink' : 'text-muted' }}">{{ $ticket->assignee?->displayName() ?? __('Unassigned') }}</dd>
                            </dl>
                        @endif

                        @if($canUpdate)
                            <x-ui.edit-in-place.select
                                id="ticket-category"
                                :label="__('Category')"
                                :value="$ticket->category ?? ''"
                                field="category"
                            >
                                <x-slot name="read">
                                    <span class="text-sm text-ink">{{ $this->categoryLabel($ticket->category) }}</span>
                                </x-slot>
                                <option value="">{{ __('None') }}</option>
                                @foreach(config('it.categories') as $code => $label)
                                    <option value="{{ $code }}">{{ __($label) }}</option>
                                @endforeach
                            </x-ui.edit-in-place.select>

                            <x-ui.edit-in-place.text
                                id="ticket-location"
                                :label="__('Location')"
                                :value="$ticket->location ?? ''"
                                field="location"
                                :empty="__('—')"
                                maxlength="255"
                            />
                        @else
                            <dl>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Category') }}</dt>
                                <dd class="mt-0.5 text-sm text-ink">{{ $this->categoryLabel($ticket->category) }}</dd>
                            </dl>
                            <dl>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Location') }}</dt>
                                <dd class="mt-0.5 text-sm text-ink">{{ $ticket->location ?? '—' }}</dd>
                            </dl>
                        @endif

                        <dl>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reporter') }}</dt>
                            <dd class="mt-0.5 text-sm text-ink">{{ $ticket->reporter?->displayName() ?? '—' }}</dd>
                        </dl>

                        <dl>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Opened') }}</dt>
                            <dd class="mt-0.5 text-sm text-ink">
                                <x-ui.datetime :value="$ticket->created_at" />
                            </dd>
                        </dl>

                        @if($ticket->resolved_at)
                            <dl>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Resolved') }}</dt>
                                <dd class="mt-0.5 text-sm text-ink">
                                    <x-ui.datetime :value="$ticket->resolved_at" />
                                </dd>
                            </dl>
                        @endif
                    </div>
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
