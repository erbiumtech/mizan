{{--
    The columns themselves — shared by the project board and by "My work".

    Takes `$columns` from `Boards::columns()` and nothing else. Both screens draw the same cards,
    and a card that read differently depending on which board you opened it from would be the
    first thing somebody reported as a bug.
--}}
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
        <section @class(['fi-board-column', 'fi-over' => $column['over']])>
            <header class="fi-board-column-head">
                <span class="fi-board-column-name">{{ $column['label'] }}</span>
                <span class="fi-board-column-count">
                    {{ $column['total'] }}@if ($column['limit']) / {{ $column['limit'] }} @endif
                </span>
            </header>

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
                        <article
                            class="fi-board-card"
                            x-sortable-item="{{ $card['id'] }}"
                            x-sortable-handle
                            wire:key="card-{{ $card['id'] }}"
                        >
                            <a href="{{ $card['url'] }}" class="fi-board-card-key">{{ $card['key'] }}</a>
                            <p class="fi-board-card-title">{{ $card['title'] }}</p>

                            @if ($card['labels'] !== [])
                                <p class="fi-board-card-labels">
                                    @foreach ($card['labels'] as $label)
                                        <span class="fi-board-chip">{{ $label['name'] }}</span>
                                    @endforeach
                                </p>
                            @endif

                            <footer class="fi-board-card-foot">
                                <span class="fi-board-type">{{ $card['type'] }}</span>

                                @if ($card['blocked'])
                                    <span class="fi-board-chip fi-blocked">{{ __('Blocked') }}</span>
                                @endif

                                @if ($card['epic'])
                                    <span class="fi-board-chip">{{ $card['epic'] }}</span>
                                @endif

                                @if ($card['subtasks'] > 0)
                                    <span class="fi-board-chip">{{ $card['subtasks_done'] }}/{{ $card['subtasks'] }}</span>
                                @endif

                                @if ($card['points'] !== null)
                                    <span class="fi-board-chip">{{ $card['points'] }}</span>
                                @endif

                                @if ($card['due_on'])
                                    <span @class(['fi-board-chip', 'fi-overdue' => $card['overdue']])>
                                        {{ $card['due_on'] }}
                                    </span>
                                @endif

                                @if ($card['initials'] !== '')
                                    <span class="fi-board-avatar" title="{{ $card['assignee'] }}">
                                        {{ $card['initials'] }}
                                    </span>
                                @endif
                            </footer>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endforeach
</div>
