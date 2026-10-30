{{--
    Everything assigned to one person, across every project — docs/project-management-plan.md §5.5.

    No project picker and no saved board: this one is derived from who is looking. Its columns are
    the three status categories rather than a workflow's statuses, because the items on it come from
    projects whose workflows need not agree about anything except those three.
--}}
{{--
    The poll goes on a plain element inside the page rather than on the component tag: Blade does not
    compile directives inside a component's attribute list, and `@if` there is a syntax error at
    render time rather than a condition.
--}}
<x-filament-panels::page>
<div @if ($this->getPollingInterval()) wire:poll.{{ $this->getPollingInterval() }} @endif>
    @php($columns = $this->columns())

    @if (collect($columns)->sum('total') === 0)
        <x-filament::section>
            <p class="fi-board-empty">
                {{ __('Nothing is assigned to you. When somebody puts your name on a work item, it appears here.') }}
            </p>
        </x-filament::section>
    @else
        @include('filament.work.columns', ['columns' => $columns])
    @endif
</div>
</x-filament-panels::page>
