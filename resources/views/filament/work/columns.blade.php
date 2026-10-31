{{--
    The columns themselves — shared by the project board and by "My work".

    Takes `$columns` from `Boards::columns()` and nothing else. Both screens draw the same cards,
    and a card that read differently depending on which board you opened it from would be the
    first thing somebody reported as a bug. `$addable` puts "+ Create" at the foot of each column
    (the project board only: "My work" has no one project to create in).

    A card opens the item panel when clicked; its key and the pencil still lead to the full page.
--}}
@php($addable = $addable ?? false)
<div
    class="fi-board"
    x-data="{
        dropped(event) {
            const items = [...event.to.querySelectorAll(':scope > [x-sortable-item]')]
            const index = items.indexOf(event.item)

            $wire.moveCard(
                parseInt(event.item.getAttribute('x-sortable-item')),
                parseInt(event.to.dataset.column),
                index > 0 ? parseInt(items[index - 1].getAttribute('x-sortable-item')) : null,
                index < items.length - 1 ? parseInt(items[index + 1].getAttribute('x-sortable-item')) : null,
            )
        },
    }"
>
    @foreach ($columns as $column)
        <section
            @class(['fi-board-column', 'fi-over' => $column['over']])
            aria-label="{{ $column['label'] }}"
            x-data="{ collapsed: false, adding: false, title: '' }"
            x-bind:class="{ 'fi-collapsed': collapsed }"
            wire:key="column-{{ $column['index'] }}"
        >
            {{-- A status-category dot, the name, a count chip that turns red at its limit, and collapse. --}}
            <header class="fi-board-column-head">
                <span @class([
                    'fi-board-column-dot',
                    'fi-board-column-dot-'.str_replace('_', '-', (string) ($column['category'] ?? 'todo')),
                ]) aria-hidden="true"></span>
                <span class="fi-board-column-name">{{ $column['label'] }}</span>
                <span @class(['fi-board-column-count', 'fi-over' => $column['full'] ?? $column['over']])>
                    {{ $column['total'] }}@if ($column['limit'] !== null) / {{ $column['limit'] }}@endif
                </span>
                <button
                    type="button"
                    class="fi-board-icon-button fi-board-collapse"
                    x-on:click="collapsed = ! collapsed"
                    x-bind:aria-expanded="(! collapsed).toString()"
                    aria-label="{{ __('Collapse :column', ['column' => $column['label']]) }}"
                >
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6 15 12 9 18" x-show="collapsed"></path><path d="m15 6-6 6 6 6" x-show="! collapsed"></path></svg>
                </button>
            </header>

            <div class="fi-board-column-body" x-show="! collapsed">
                @foreach ($column['lanes'] as $lane)
                    @if ($lane['label'] !== '')
                        <h3 class="fi-board-lane">{{ $lane['label'] }}</h3>
                    @endif

                    <div
                        class="fi-board-stack"
                        data-column="{{ $column['index'] }}"
                        x-sortable
                        x-sortable-group="board"
                        x-on:end="dropped($event)"
                        wire:key="col-{{ $column['index'] }}-lane-{{ $lane['key'] }}"
                    >
                        @foreach ($lane['cards'] as $card)
                            @php($priority = strtolower((string) ($card['priority'] ?? '')))
                            @php($type = strtolower((string) $card['type']))
                            <article
                                class="fi-board-card"
                                x-sortable-item="{{ $card['id'] }}"
                                x-sortable-handle
                                wire:key="card-{{ $card['id'] }}"
                                x-on:click="if (! $event.target.closest('a, button')) $wire.openItem({{ $card['id'] }})"
                            >
                                <div class="fi-board-card-actions">
                                    <a href="{{ $card['url'] }}" class="fi-board-icon-button" title="{{ __('Edit') }}" aria-label="{{ __('Edit :key', ['key' => $card['key']]) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16zM14 6l4 4"></path></svg>
                                    </a>
                                    <button type="button" class="fi-board-icon-button" wire:click="openItem({{ $card['id'] }})" title="{{ __('More') }}" aria-label="{{ __('Open :key', ['key' => $card['key']]) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="19" cy="12" r="1.8"></circle></svg>
                                    </button>
                                </div>

                                <button type="button" class="fi-board-card-title" wire:click="openItem({{ $card['id'] }})">{{ $card['title'] }}</button>

                                @if ($card['labels'] !== [] || $card['blocked'] || $card['epic'] || $card['subtasks'] > 0 || $card['points'] !== null || $card['due_on'])
                                    <p class="fi-board-card-labels">
                                        @if ($card['blocked'])
                                            <span class="fi-board-chip fi-blocked">{{ __('Blocked') }}</span>
                                        @endif

                                        @foreach ($card['labels'] as $label)
                                            <span class="fi-board-chip fi-board-label">{{ $label['name'] }}</span>
                                        @endforeach

                                        @if ($card['epic'])
                                            <span class="fi-board-chip fi-board-epic" title="{{ __('Parent') }}">{{ $card['epic'] }}</span>
                                        @endif

                                        @if ($card['subtasks'] > 0)
                                            <span class="fi-board-chip" title="{{ __('Subtasks done') }}">{{ $card['subtasks_done'] }}/{{ $card['subtasks'] }}</span>
                                        @endif

                                        @if ($card['points'] !== null)
                                            <span class="fi-board-chip" title="{{ __('Points') }}">{{ $card['points'] }}</span>
                                        @endif

                                        @if ($card['due_on'])
                                            <span @class(['fi-board-chip', 'fi-overdue' => $card['overdue']]) title="{{ __('Due') }}">{{ $card['due_on'] }}</span>
                                        @endif
                                    </p>
                                @endif

                                <footer class="fi-board-card-foot">
                                    @include('filament.work.type-icon', ['type' => $type])
                                    <a href="{{ $card['url'] }}" class="fi-board-card-key">{{ $card['key'] }}</a>

                                    <span class="fi-board-card-spacer"></span>

                                    @if ($priority !== '' && $priority !== 'none')
                                        @include('filament.work.priority-icon', ['priority' => $priority])
                                    @endif

                                    @if ($card['initials'] !== '')
                                        <span class="fi-board-avatar" title="{{ $card['assignee'] }}">
                                            {{ $card['initials'] }}
                                        </span>
                                    @else
                                        <span class="fi-board-avatar fi-empty" title="{{ __('Unassigned') }}" aria-label="{{ __('Unassigned') }}">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 3.6-6 8-6s8 2 8 6"></path></svg>
                                        </span>
                                    @endif
                                </footer>
                            </article>
                        @endforeach
                    </div>
                @endforeach

                @if ($addable)
                    <button type="button" class="fi-board-add" x-show="! adding" x-on:click="adding = true; $nextTick(() => $refs.title.focus())">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
                        {{ __('Create') }}
                    </button>
                    <form
                        class="fi-board-add-form"
                        x-show="adding"
                        x-cloak
                        x-on:submit.prevent="if (title.trim() !== '') { $wire.quickAdd({{ $column['index'] }}, title); title = '' }"
                    >
                        <input
                            type="text"
                            x-ref="title"
                            x-model="title"
                            maxlength="255"
                            x-on:keydown.escape="adding = false; title = ''"
                            x-on:blur="if (title.trim() === '') adding = false"
                            placeholder="{{ __('What needs to be done?') }}"
                            aria-label="{{ __('New work item in :column', ['column' => $column['label']]) }}"
                        />
                    </form>
                @endif
            </div>
        </section>
    @endforeach
</div>
