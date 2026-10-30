{{--
    The backlog — docs/project-management-plan.md §6.1.

    A ranked list, dragged with the same `x-sortable` the board uses, and an inline create at the
    bottom that takes a title and a type. The full form is a click away on the key.
--}}
<x-filament-panels::page>
    @php($items = $this->items())
    @php($sprints = $this->sprints())
    @php($releases = $this->releases())
    @php($epics = $this->epics())

    <div class="fi-board-bar">
        <select wire:model.live="project" class="fi-board-project" aria-label="{{ __('Project') }}">
            @foreach ($this->projects() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>

        {{-- Only once something is ticked: a row of disabled buttons is noise on every other visit. --}}
        @if ($this->selected !== [])
            <div class="fi-board-toggles">
                <span class="fi-backlog-chosen">{{ count($this->selected) }} {{ __('chosen') }}</span>
                @foreach ($sprints as $id => $name)
                    <button type="button" wire:click="moveToSprint({{ $id }})" class="fi-board-toggle">
                        {{ __('To') }} {{ $name }}
                    </button>
                @endforeach
                <button type="button" wire:click="moveToSprint" class="fi-board-toggle">
                    {{ __('Out of a sprint') }}
                </button>

                {{-- The other two bulk changes §6.1 asks for: a release and an epic, each as a pick. --}}
                @if ($releases !== [])
                    <select class="fi-board-project" aria-label="{{ __('Release') }}"
                            x-on:change="$wire.setRelease($event.target.value === '' ? null : parseInt($event.target.value)); $event.target.value = '-'">
                        <option value="-" selected disabled>{{ __('Release…') }}</option>
                        @foreach ($releases as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                        <option value="">{{ __('No release') }}</option>
                    </select>
                @endif

                @if ($epics !== [])
                    <select class="fi-board-project" aria-label="{{ __('Epic') }}"
                            x-on:change="$wire.setEpic($event.target.value === '' ? null : parseInt($event.target.value)); $event.target.value = '-'">
                        <option value="-" selected disabled>{{ __('Epic…') }}</option>
                        @foreach ($epics as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                        <option value="">{{ __('No epic') }}</option>
                    </select>
                @endif
            </div>
        @endif
    </div>

    <x-filament::section>
        @if ($items->isEmpty())
            <p class="fi-board-empty">
                {{ __('Nothing waiting. Everything on this project is in a sprint, finished, or not written down yet.') }}
            </p>
        @else
            <ol
                class="fi-backlog"
                x-sortable
                x-data="{
                    dropped(event) {
                        const rows = [...event.to.querySelectorAll(':scope > [x-sortable-item]')]
                        const index = rows.indexOf(event.item)

                        $wire.reorder(
                            parseInt(event.item.getAttribute('x-sortable-item')),
                            index > 0 ? parseInt(rows[index - 1].getAttribute('x-sortable-item')) : null,
                            index < rows.length - 1 ? parseInt(rows[index + 1].getAttribute('x-sortable-item')) : null,
                        )
                    },
                }"
                x-on:end="dropped($event)"
            >
                @foreach ($items as $item)
                    <li class="fi-backlog-row" x-sortable-item="{{ $item->getKey() }}" wire:key="row-{{ $item->getKey() }}">
                        <span x-sortable-handle class="fi-backlog-grip" aria-hidden="true">⠿</span>

                        <input
                            type="checkbox"
                            value="{{ $item->getKey() }}"
                            wire:model.live="selected"
                            aria-label="{{ __('Choose') }} {{ $item->key }}"
                        />

                        <a href="{{ \App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource::getUrl('edit', ['record' => $item]) }}"
                           class="fi-board-card-key">{{ $item->key }}</a>

                        <span class="fi-backlog-title">{{ $item->title }}</span>

                        <span class="fi-board-type">{{ $item->type }}</span>

                        @if ($item->estimate_points !== null)
                            <span class="fi-board-chip">{{ $item->estimate_points }}</span>
                        @endif

                        @if ($item->release)
                            <span class="fi-board-chip" title="{{ __('Promised to this release') }}">{{ $item->release->name }}</span>
                        @endif

                        @if ($item->carried_over > 0)
                            {{-- How many sprints it has slipped. Worth seeing while planning the next one. --}}
                            <span class="fi-board-chip" title="{{ __('Carried out of this many sprints') }}">
                                ↻ {{ $item->carried_over }}
                            </span>
                        @endif

                        <span class="fi-backlog-status">{{ $item->status?->name }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Written at speed: a title and a type, and Enter files it. --}}
        <form wire:submit.prevent="add" class="fi-backlog-add">
            <input
                type="text"
                wire:model="newTitle"
                class="fi-board-search fi-backlog-add-title"
                placeholder="{{ __('Add to the backlog and press Enter') }}"
                aria-label="{{ __('New work item') }}"
            />

            <select wire:model="type" class="fi-board-project" aria-label="{{ __('Type') }}">
                @foreach ($this->types() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <button type="submit" class="fi-board-toggle fi-on">{{ __('Add') }}</button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
