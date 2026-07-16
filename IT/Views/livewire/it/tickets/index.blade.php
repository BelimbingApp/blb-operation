<?php
/** @var \App\Modules\Operation\IT\Livewire\Tickets\Index $this */
?>

<div>
    <x-slot name="title">{{ __('IT Tickets') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('IT Tickets')" :subtitle="__('Track and resolve IT support requests')">
            <x-slot name="actions">
                <x-ui.link href="{{ route('it.tickets.board') }}" wire:navigate>
                    {{ __('Board') }}
                </x-ui.link>
                <x-ui.button variant="primary" as="a" href="{{ route('it.tickets.create') }}" wire:navigate>
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('New Ticket') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.session-flash />

        <x-ui.card>
            <x-ui.stat-strip>
                <x-ui.stat :label="__('Open')">
                    {{ $stats['open'] }}
                    <x-slot name="sub">{{ __(':count unassigned', ['count' => $stats['unassigned']]) }}</x-slot>
                </x-ui.stat>
                <x-ui.stat :label="__('Active')">
                    {{ $stats['active'] }}
                    <x-slot name="sub">{{ __(':count mine', ['count' => $stats['mine']]) }}</x-slot>
                </x-ui.stat>
                <x-ui.stat :label="__('Waiting')">
                    {{ $stats['waiting'] }}
                    <x-slot name="sub">{{ __(':count blocked', ['count' => $stats['blocked']]) }}</x-slot>
                </x-ui.stat>
                <x-ui.stat :label="__('Resolved')">
                    {{ $stats['resolved_week'] }}
                    <x-slot name="sub">{{ __('past 7 days') }}</x-slot>
                </x-ui.stat>
            </x-ui.stat-strip>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-2 space-y-2">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div x-data @segmented-control-change="$wire.setScope($event.detail.value)">
                        <x-ui.segmented-control
                            :options="[
                                ['value' => 'open', 'label' => __('Open')],
                                ['value' => 'mine', 'label' => __('Mine')],
                                ['value' => 'unassigned', 'label' => __('Unassigned')],
                                ['value' => 'done', 'label' => __('Done')],
                                ['value' => 'all', 'label' => __('All')],
                            ]"
                            :value="$scope"
                            :label="__('Queue lens')"
                        />
                    </div>
                    <div class="flex-1">
                        <x-ui.search-input
                            id="ticket-search"
                            wire:model.live.debounce.300ms="search"
                            aria-label="{{ __('Search tickets') }}"
                            placeholder="{{ __('Search by title, description, location, or people...') }}"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <x-ui.select id="status-filter" wire:model.live="statusFilter" aria-label="{{ __('Filter by status') }}">
                        <option value="">{{ __('All Statuses') }}</option>
                        @foreach($this->ticketStatuses() as $code => $label)
                            <option value="{{ $code }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="priority-filter" wire:model.live="priorityFilter" aria-label="{{ __('Filter by priority') }}">
                        <option value="">{{ __('All Priorities') }}</option>
                        @foreach(config('it.priorities') as $code => $label)
                            <option value="{{ $code }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="category-filter" wire:model.live="categoryFilter" aria-label="{{ __('Filter by category') }}">
                        <option value="">{{ __('All Categories') }}</option>
                        @foreach(config('it.categories') as $code => $label)
                            <option value="{{ $code }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="assignee-filter" wire:model.live="assigneeFilter" aria-label="{{ __('Filter by assignee') }}">
                        <option value="">{{ __('All Assignees') }}</option>
                        <option value="none">{{ __('Unassigned') }}</option>
                        @foreach($assigneeOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </div>

            <x-ui.table container="flush" :caption="__('IT tickets')">
                <x-slot name="head">
                    <tr>
                        <x-ui.sortable-th
                            column="id"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('id')"
                            :label="'#'"
                        />
                        <x-ui.sortable-th
                            column="title"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('title')"
                            :label="__('Title')"
                        />
                        <x-ui.sortable-th
                            column="reporter_name"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('reporter_name')"
                            :label="__('Reporter')"
                        />
                        <x-ui.sortable-th
                            column="assignee_name"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('assignee_name')"
                            :label="__('Assignee')"
                        />
                        <x-ui.sortable-th
                            column="priority"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('priority')"
                            :label="__('Priority')"
                        />
                        <x-ui.sortable-th
                            column="status"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('status')"
                            :label="__('Status')"
                        />
                        <x-ui.sortable-th
                            column="category"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('category')"
                            :label="__('Category')"
                        />
                        <x-ui.sortable-th
                            column="created_at"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('created_at')"
                            :label="__('Created')"
                        />
                    </tr>
                </x-slot>

                @forelse($tickets as $ticket)
                    <tr wire:key="ticket-{{ $ticket->id }}">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $ticket->id }}</td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <a href="{{ route('it.tickets.show', $ticket) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $ticket->title }}</a>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $ticket->reporter?->displayName() ?? '—' }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm {{ $ticket->assignee ? 'text-ink' : 'text-muted' }}">{{ $ticket->assignee?->displayName() ?? '—' }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            <x-ui.badge :variant="$this->priorityVariant($ticket->priority)">{{ $this->priorityLabel($ticket->priority) }}</x-ui.badge>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            <x-ui.badge :variant="$this->statusVariant($ticket->status)">{{ $this->statusLabel($ticket->status) }}</x-ui.badge>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $this->categoryLabel($ticket->category) }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                            <span title="{{ $ticket->created_at?->format('Y-m-d H:i:s') }}">{{ $ticket->created_at?->diffForHumans() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            @if($search !== '' || $statusFilter !== '' || $priorityFilter !== '' || $categoryFilter !== '' || $assigneeFilter !== '')
                                {{ __('No tickets match these filters.') }}
                            @elseif($scope === 'open')
                                {{ __('No open tickets — the queue is clear.') }}
                            @elseif($scope === 'mine')
                                {{ __('Nothing assigned to you right now.') }}
                            @elseif($scope === 'unassigned')
                                {{ __('Every open ticket has an owner.') }}
                            @elseif($scope === 'done')
                                {{ __('No resolved or closed tickets yet.') }}
                            @else
                                {{ __('No tickets found.') }}
                            @endif
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="mt-2">
                {{ $tickets->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
