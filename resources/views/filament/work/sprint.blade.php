{{--
    A sprint: what is in it, how it is burning down, and what the team has managed before.

    The burndown is drawn as bars rather than a canvas chart — no charting library is loaded for two
    series over a fortnight, and bars read the same on a phone. `remaining` against `ideal` is the
    only comparison it is for.
--}}
<x-filament-panels::page>
    @php($progress = $this->progress())
    @php($burndown = $this->burndown())
    @php($velocity = $this->velocity())

    <x-filament::section :heading="__('Where it is up to')">
        <div class="fi-sprint-figures">
            <div class="fi-sprint-figure">
                <span class="fi-sprint-figure-label">{{ __('Items') }}</span>
                <span class="fi-sprint-figure-value">{{ $progress['done'] }} / {{ $progress['items'] }}</span>
            </div>
            <div class="fi-sprint-figure">
                <span class="fi-sprint-figure-label">{{ __('Points') }}</span>
                <span class="fi-sprint-figure-value">{{ $progress['points_done'] }} / {{ $progress['points'] }}</span>
            </div>
            {{-- Carried in from an earlier sprint: the number that makes a velocity honest. --}}
            <div class="fi-sprint-figure">
                <span class="fi-sprint-figure-label">{{ __('Carried in') }}</span>
                <span class="fi-sprint-figure-value">{{ $progress['carried'] }}</span>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Burndown')">
        @if ($burndown === [])
            <p class="fi-board-empty">
                {{ __('A burndown needs a start date, an end date and something in the sprint.') }}
            </p>
        @else
            <div class="fi-burndown">
                @php($ceiling = max(collect($burndown)->max('remaining'), 1))
                @foreach ($burndown as $day)
                    <div class="fi-burndown-day" title="{{ $day['day'] }}: {{ $day['remaining'] }} left">
                        <div class="fi-burndown-bars">
                            {{-- The ideal is a line to read the real one against, not a target. --}}
                            <span class="fi-burndown-ideal" style="height: {{ round($day['ideal'] / $ceiling * 100) }}%"></span>
                            <span class="fi-burndown-actual" style="height: {{ round($day['remaining'] / $ceiling * 100) }}%"></span>
                        </div>
                        <span class="fi-burndown-label">{{ $day['day'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Velocity')" :description="__('What closed sprints on this project delivered, oldest first.')">
        @if ($velocity === [])
            <p class="fi-board-empty">{{ __('No sprint has been closed on this project yet.') }}</p>
        @else
            <table class="fi-sprint-velocity">
                <thead>
                    <tr>
                        <th>{{ __('Sprint') }}</th>
                        <th>{{ __('Items') }}</th>
                        <th>{{ __('Points') }}</th>
                        <th>{{ __('Of which carried in') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($velocity as $row)
                        <tr>
                            <td>{{ $row['sprint'] }}</td>
                            <td>{{ $row['items'] }}</td>
                            <td>{{ $row['points'] }}</td>
                            <td>{{ $row['carried'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
