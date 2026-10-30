{{--
    The Kanban board — docs/project-management-plan.md §5.

    Everything here is drawn from what `Boards::columns()` returned. The view asks no questions of a
    model and makes no decision about what may move where: a drop calls `moveCard`, and the card
    lands wherever the next render says it did, which is also what makes a refusal put it back
    without any code to undo the drag.

    `x-sortable` is Filament's own directive, already in the panel's bundle for reorderable tables.
    The shared `x-sortable-group` is what lets a card leave one column and enter another.
--}}
{{--
    The poll goes on a plain element inside the page rather than on the component tag: Blade does not
    compile directives inside a component's attribute list, and `@if` there is a syntax error at
    render time rather than a condition.
--}}
<x-filament-panels::page>
<div @if ($this->getPollingInterval()) wire:poll.{{ $this->getPollingInterval() }} @endif>
    @php($columns = $this->columns())
    @php($unmapped = $this->unmapped())

    @if ($columns === [])
        <x-filament::section>
            <p class="fi-board-empty">
                {{ __('There is no board here yet. Create a project and a work item, and this fills itself.') }}
            </p>
        </x-filament::section>
    @else
        {{-- Quick filters. They live in the URL, so a filtered board is a link somebody can send. --}}
        <div class="fi-board-bar">
            <select wire:model.live="project" class="fi-board-project" aria-label="{{ __('Project') }}">
                @foreach ($this->projects() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>

            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                class="fi-board-search"
                placeholder="{{ __('Search this board') }}"
                aria-label="{{ __('Search this board') }}"
            />

            <div class="fi-board-toggles">
                <button type="button" wire:click="$toggle('mine')" @class(['fi-board-toggle', 'fi-on' => $this->mine])>
                    {{ __('Mine') }}
                </button>
                <button type="button" wire:click="$toggle('unassigned')" @class(['fi-board-toggle', 'fi-on' => $this->unassigned])>
                    {{ __('Unassigned') }}
                </button>
                <button type="button" wire:click="$toggle('blocked')" @class(['fi-board-toggle', 'fi-on' => $this->blocked])>
                    {{ __('Blocked') }}
                </button>
            </div>
        </div>

        {{--
            A status no column shows is said out loud. A board that silently swallows cards is the
            first thing a team blames the tool for — §5.2.
        --}}
        @if ($unmapped !== [])
            <p class="fi-board-unmapped">
                {{ __('Not on this board:') }} {{ implode(', ', $unmapped) }}.
                {{ __('Items moved there will disappear from it until a column shows them.') }}
            </p>
        @endif

        @include('filament.work.columns', ['columns' => $columns])
    @endif
</div>
</x-filament-panels::page>
