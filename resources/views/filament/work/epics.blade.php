{{--
    The Epics of a project — docs/project-management-plan.md §6.3.

    One card per Epic: the bar, the figures behind it, and its children split into open and done.
    The bar is by count rather than by points, because the children of an unestimated Epic still
    get finished one at a time.
--}}
<x-filament-panels::page>
    @php($epics = $this->epics())

    <div class="fi-board-bar">
        <select wire:model.live="project" class="fi-board-project" aria-label="{{ __('Project') }}">
            @foreach ($this->projects() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>

        <div class="fi-board-toggles">
            <button type="button" wire:click="$toggle('done')" class="fi-board-toggle {{ $this->done ? 'fi-on' : '' }}">
                {{ __('Show finished') }}
            </button>
        </div>
    </div>

    @if ($epics->isEmpty())
        <x-filament::section>
            <p class="fi-board-empty">
                {{ $this->done
                    ? __('No epics on this project. An epic is a work item of type Epic; its stories name it as their parent.')
                    : __('No open epics on this project. Finished ones are behind the toggle above.') }}
            </p>
        </x-filament::section>
    @else
        <div class="fi-epics">
            @foreach ($epics as $row)
                @php($epic = $row['epic'])
                @php($progress = $row['progress'])

                <x-filament::section wire:key="epic-{{ $epic->getKey() }}">
                    <x-slot name="heading">
                        <a href="{{ \App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource::getUrl('edit', ['record' => $epic]) }}"
                           class="fi-board-card-key">{{ $epic->key }}</a>
                        {{ $epic->title }}
                    </x-slot>

                    <x-slot name="description">
                        {{ $epic->status?->name }}
                        @if ($epic->assignee)
                            · {{ $epic->assignee->display_label }}
                        @endif
                        @if ($epic->due_on)
                            · {{ __('due') }} {{ $epic->due_on->format('d M Y') }}
                        @endif
                    </x-slot>

                    <div class="fi-epic-progress" role="progressbar" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                        <span class="fi-epic-progress-bar" style="width: {{ $progress['percent'] }}%"></span>
                    </div>

                    <div class="fi-sprint-figures fi-epic-figures">
                        <div class="fi-sprint-figure">
                            <span class="fi-sprint-figure-label">{{ __('Done') }}</span>
                            <span class="fi-sprint-figure-value">{{ $progress['done'] }} / {{ $progress['total'] }}</span>
                        </div>
                        @if ($progress['points'] > 0)
                            <div class="fi-sprint-figure">
                                <span class="fi-sprint-figure-label">{{ __('Points') }}</span>
                                <span class="fi-sprint-figure-value">{{ $progress['points_done'] }} / {{ $progress['points'] }}</span>
                            </div>
                        @endif
                        <div class="fi-sprint-figure">
                            <span class="fi-sprint-figure-label">{{ __('Progress') }}</span>
                            <span class="fi-sprint-figure-value">{{ $progress['percent'] }}%</span>
                        </div>
                    </div>

                    @if ($progress['total'] === 0)
                        <p class="fi-board-empty">{{ __('Nothing under it yet. A story names this epic as its parent to count here.') }}</p>
                    @else
                        <div class="fi-epic-children">
                            <div>
                                <h4 class="fi-epic-children-heading">{{ __('Open') }} · {{ $row['open']->count() }}</h4>
                                <ul class="fi-epic-list">
                                    @foreach ($row['open'] as $child)
                                        <li>
                                            <a href="{{ \App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource::getUrl('edit', ['record' => $child]) }}"
                                               class="fi-board-card-key">{{ $child->key }}</a>
                                            <span class="fi-backlog-title">{{ $child->title }}</span>
                                            <span class="fi-backlog-status">{{ $child->status?->name }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <h4 class="fi-epic-children-heading">{{ __('Done') }} · {{ $row['done']->count() }}</h4>
                                <ul class="fi-epic-list fi-epic-list-done">
                                    @foreach ($row['done'] as $child)
                                        <li>
                                            <a href="{{ \App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource::getUrl('edit', ['record' => $child]) }}"
                                               class="fi-board-card-key">{{ $child->key }}</a>
                                            <span class="fi-backlog-title">{{ $child->title }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
