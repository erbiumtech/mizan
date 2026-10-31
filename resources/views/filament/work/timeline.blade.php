{{--
    The timeline — docs/project-management-plan.md §6.5.

    Epics and releases as bars on a month-scale ruler, items as ticks beneath their Epic when
    dated, sprints as a faint band. Read-only: every position is a percentage computed on the
    server, and there is nothing here to drag.
--}}
<x-filament-panels::page>
    @php($t = $this->timeline())

    <div class="fi-board-bar">
        <select wire:model.live="project" class="fi-board-project" aria-label="{{ __('Project') }}">
            @foreach ($this->projects() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        @if ($t['from'] !== null)
            <span class="fi-backlog-status">{{ $t['from'] }} → {{ $t['to'] }}</span>
        @endif
    </div>

    <x-filament::section>
        @if ($t['months'] === [])
            <p class="fi-board-empty">
                {{ __('Nothing dated yet. An epic with a due date, a child with one, a release with a planned date or a sprint with dates will draw here.') }}
            </p>
        @else
            <div class="fi-timeline">
                <div class="fi-timeline-months">
                    @foreach ($t['months'] as $month)
                        <span class="fi-timeline-month" style="left: {{ $month['left'] }}%; width: {{ $month['width'] }}%">{{ $month['label'] }}</span>
                    @endforeach
                </div>

                @if ($t['sprints'] !== [])
                    <div class="fi-timeline-row fi-timeline-sprints">
                        <span class="fi-timeline-label">{{ __('Sprints') }}</span>
                        <div class="fi-timeline-track">
                            @foreach ($t['sprints'] as $sprint)
                                <span class="fi-timeline-sprint fi-timeline-sprint-{{ $sprint['state'] }}" style="left: {{ $sprint['left'] }}%; width: {{ $sprint['width'] }}%" title="{{ $sprint['name'] }}: {{ $sprint['from']->format('d M') }} – {{ $sprint['to']->format('d M') }}">{{ $sprint['name'] }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach ($t['epics'] as $row)
                    <div class="fi-timeline-row">
                        <a class="fi-timeline-label fi-board-card-key" href="{{ \App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource::getUrl('edit', ['record' => $row['epic']]) }}">
                            {{ $row['epic']->key }} <span class="fi-backlog-title">{{ $row['epic']->title }}</span>
                        </a>
                        <div class="fi-timeline-track">
                            <span class="fi-timeline-bar" style="left: {{ $row['left'] }}%; width: {{ $row['width'] }}%" title="{{ $row['from']->format('d M Y') }} – {{ $row['to']->format('d M Y') }} · {{ $row['percent'] }}%">
                                <span class="fi-timeline-bar-done" style="width: {{ $row['percent'] }}%"></span>
                            </span>
                            @foreach ($row['ticks'] as $tick)
                                <span class="fi-timeline-tick {{ $tick['done'] ? 'fi-done' : '' }}" style="left: {{ $tick['left'] }}%" title="{{ $tick['key'] }} {{ $tick['title'] }} · {{ __('due') }} {{ $tick['on']->format('d M') }}"></span>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if ($t['releases'] !== [])
                    <div class="fi-timeline-row fi-timeline-releases">
                        <span class="fi-timeline-label">{{ __('Releases') }}</span>
                        <div class="fi-timeline-track">
                            @foreach ($t['releases'] as $release)
                                <span class="fi-timeline-release {{ $release['released'] ? 'fi-done' : '' }}" style="left: {{ $release['left'] }}%" title="{{ $release['name'] }} · {{ $release['on']->format('d M Y') }}{{ $release['released'] ? ' · '.__('released') : '' }}">
                                    <span class="fi-timeline-release-label">{{ $release['name'] }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
