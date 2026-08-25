{{--
    The dashboard's arranger — docs/reports-expansion-plan.md Phase 7, item 6.

    "The interaction is Filament's own. `x-sortable` with a drag handle on each widget header, persisting on
    `onEnd` through a Livewire call — SortableJS is already bundled in `filament/support`, so this adds no
    dependency and behaves like the reorderable tables people already use here."

    **A list of cards rather than the widgets themselves, and the reason is not laziness.** Two things stand
    in the way of dragging live widgets. A drag handle has to live in each widget's *header*, and a stats
    overview has no header at all — three of the kinds on this dashboard render markup this application does
    not own, so a handle would mean overriding Filament's widget views rather than using them. And every drop
    is a Livewire round trip: leaving twenty-three widgets rendered would remount each one and re-run the
    aggregates behind it every time somebody moved a card, so the thing being arranged would spend the whole
    session loading.

    What this is instead is the shape Filament already uses for exactly this job — its column manager, which
    is a list of names with a checkbox and a handle each. Same directives, same handle icon, same behaviour.

    `wire:key` on every row, because a Livewire list that reorders itself without keys re-uses the DOM node
    that happens to be in that position and moves the wrong card back.
--}}
<div class="fi-arranger">
    <div class="fi-arranger-intro">
        <p class="fi-arranger-lead">Drag to reorder. Hidden widgets stay in this list so you can bring them back.</p>
        <p class="fi-arranger-note">
            This is your own dashboard — nobody else's changes. Widgets you do not have permission to open, or
            whose module is switched off, are not listed at all.
        </p>
    </div>

    <div
        class="fi-arranger-list"
        role="list"
        x-sortable
        x-on:end.stop="$wire.reorderWidgets($event.target.sortable.toArray())"
        data-sortable-animation-duration="300"
    >
        @foreach ($rows as $row)
            <div
                wire:key="arranger-{{ $row['alias'] }}"
                x-sortable-item="{{ $row['alias'] }}"
                @class(['fi-arranger-row', 'fi-arranger-row-hidden' => $row['hidden']])
                role="listitem"
            >
                {{-- The handle. A button so it is reachable by keyboard focus and announced as a control. --}}
                <button
                    type="button"
                    x-sortable-handle
                    x-on:click.stop
                    class="fi-arranger-handle"
                    aria-label="Reorder {{ $row['label'] }}"
                >
                    <x-filament::icon icon="heroicon-m-bars-2" class="fi-arranger-handle-icon" />
                </button>

                <div class="fi-arranger-name">
                    <span class="fi-arranger-label">{{ $row['label'] }}</span>
                    @if ($row['module'])
                        <span class="fi-arranger-module">{{ str($row['module'])->headline() }}</span>
                    @endif
                </div>

                {{--
                    The width, as a select rather than three buttons: three buttons per row is sixty-nine
                    controls on this screen, and "Two thirds" reads as what it is where an icon would not.
                --}}
                <label class="fi-arranger-width">
                    <span class="fi-sr-only">Width of {{ $row['label'] }}</span>
                    <select
                        class="fi-explorer-select"
                        wire:change="setWidgetWidth('{{ $row['alias'] }}', $event.target.value)"
                    >
                        @foreach ($widths as $value => $label)
                            <option value="{{ $value }}" @selected($row['width'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                {{--
                    Hide and unhide, one control. Its label says what pressing it does rather than what the
                    state is, because an eye icon alone is read both ways by different people.
                --}}
                <button
                    type="button"
                    wire:click="toggleWidget('{{ $row['alias'] }}')"
                    class="fi-arranger-toggle"
                    aria-label="{{ $row['hidden'] ? 'Show' : 'Hide' }} {{ $row['label'] }}"
                    title="{{ $row['hidden'] ? 'Show this widget' : 'Hide this widget' }}"
                >
                    <x-filament::icon
                        :icon="$row['hidden'] ? 'heroicon-m-eye-slash' : 'heroicon-m-eye'"
                        class="fi-arranger-toggle-icon"
                    />
                </button>
            </div>
        @endforeach

        @if ($rows === [])
            <p class="fi-arranger-empty">
                There is nothing to arrange: no widget on this dashboard is one your role can open.
            </p>
        @endif
    </div>
</div>
