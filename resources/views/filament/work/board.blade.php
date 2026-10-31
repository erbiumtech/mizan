{{--
    The Kanban board — docs/project-management-plan.md §5.

    Everything here is drawn from what `Boards::columns()` returned. The view asks no questions of a
    model and makes no decision about what may move where: a drop calls `moveCard`, and the card
    lands wherever the next render says it did, which is also what makes a refusal put it back
    without any code to undo the drag.

    Laid out as a project space: the space's tabs, a toolbar (search, the people on the board,
    Filter and Group menus, the project and board pickers), compact columns, and the item panel a
    card opens. The quick filters, the person, the grouping and the open item all live in the URL,
    so whatever is on screen is a link somebody can send.
--}}
{{--
    The poll goes on a plain element inside the page rather than on the component tag: Blade does not
    compile directives inside a component's attribute list, and `@if` there is a syntax error at
    render time rather than a condition.
--}}
<x-filament-panels::page>
<div class="fi-wb" @if ($this->getPollingInterval()) wire:poll.{{ $this->getPollingInterval() }} @endif>
    @php($columns = $this->columns())
    @php($unmapped = $this->unmapped())

    {{-- The space's tabs: the same project in each of its views. --}}
    @php($tabs = $this->tabs())
    @if (count($tabs) > 1)
        <nav class="fi-wb-tabs" aria-label="{{ __('Project views') }}">
            @foreach ($tabs as $tab)
                @if ($tab['current'])
                    <a @if (filled($tab['url'])) href="{{ $tab['url'] }}" @endif class="fi-wb-tab fi-selected" aria-current="page">{{ $tab['label'] }}</a>
                @else
                    <a href="{{ $tab['url'] }}" class="fi-wb-tab" wire:navigate>{{ $tab['label'] }}</a>
                @endif
            @endforeach
        </nav>
    @endif

    @if ($columns === [])
        <x-filament::section>
            <p class="fi-board-empty">
                {{ __('There is no board here yet. Create a project and a work item, and this fills itself.') }}
            </p>
        </x-filament::section>
    @else
        <div class="fi-wb-toolbar">
            <label class="fi-wb-search">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('Search board') }}"
                    aria-label="{{ __('Search board') }}"
                />
            </label>

            {{-- Who has cards here. One click shows only theirs; the same click again shows everybody. --}}
            @php($people = $this->people())
            @if ($people !== [])
                <div class="fi-wb-people" role="group" aria-label="{{ __('Assignee') }}">
                    @foreach ($people as $person)
                        <button
                            type="button"
                            wire:click="pickAssignee({{ $person['id'] }})"
                            @class(['fi-wb-person', 'fi-on' => $person['on']])
                            aria-pressed="{{ $person['on'] ? 'true' : 'false' }}"
                            title="{{ $person['name'] }}"
                        >
                            <span class="sr-only">{{ $person['name'] }}</span>
                            <span aria-hidden="true">{{ $person['initials'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Filter: the URL's quick filters, behind one button with a count of those on. --}}
            @php($active = $this->activeFilterCount())
            <div class="fi-wb-menu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                <button type="button" @class(['fi-wb-button', 'fi-on' => $active > 0]) x-on:click="open = ! open" x-bind:aria-expanded="open">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"></path></svg>
                    {{ __('Filter') }}
                    @if ($active > 0)
                        <span class="fi-wb-badge">{{ $active }}</span>
                    @endif
                </button>
                <div class="fi-wb-popover" x-show="open" x-cloak x-transition.opacity role="group" aria-label="{{ __('Quick filters') }}">
                    @foreach (['mine' => __('Mine'), 'unassigned' => __('Unassigned'), 'blocked' => __('Blocked')] as $filter => $label)
                        <button
                            type="button"
                            wire:click="$toggle('{{ $filter }}')"
                            aria-pressed="{{ $this->{$filter} ? 'true' : 'false' }}"
                            @class(['fi-wb-option', 'fi-on' => $this->{$filter}])
                        >
                            <span class="fi-wb-check" aria-hidden="true">
                                @if ($this->{$filter})
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m5 12 5 5 9-10"></path></svg>
                                @endif
                            </span>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Group: the lanes, for this view only — the board's own setting is untouched. --}}
            @php($grouping = $this->currentGrouping())
            <div class="fi-wb-menu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                <button type="button" @class(['fi-wb-button', 'fi-on' => $grouping !== 'none']) x-on:click="open = ! open" x-bind:aria-expanded="open">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5h16v5H4zM4 14h16v5H4z"></path></svg>
                    {{ __('Group') }}
                </button>
                <div class="fi-wb-popover" x-show="open" x-cloak x-transition.opacity role="group" aria-label="{{ __('Group') }}">
                    @foreach ($this->groupings() as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('group', '{{ $value }}')"
                            x-on:click="open = false"
                            aria-pressed="{{ $grouping === $value ? 'true' : 'false' }}"
                            @class(['fi-wb-option', 'fi-on' => $grouping === $value])
                        >
                            <span class="fi-wb-check" aria-hidden="true">
                                @if ($grouping === $value)
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m5 12 5 5 9-10"></path></svg>
                                @endif
                            </span>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <span class="fi-wb-spacer"></span>

            <select wire:model.live="project" class="fi-board-project" aria-label="{{ __('Project') }}">
                @foreach ($this->projects() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>

            {{-- The project's boards, when it has more than one. In the address as `board`, so a link opens this one. --}}
            @php($boards = $this->boards())
            @if (count($boards) > 1)
                <select wire:model.live="boardId" class="fi-board-project" aria-label="{{ __('Board') }}">
                    @foreach ($boards as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
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

        @include('filament.work.columns', ['columns' => $columns, 'addable' => auth()->user()?->can('create', \App\Modules\Work\Models\WorkItem::class) ?? false])
    @endif

    @include('filament.work.item-panel', ['panel' => $this->panel()])
</div>
</x-filament-panels::page>
