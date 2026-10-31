{{--
    "Waiting for you" — App\Modules\Core\Filament\Widgets\WaitingForYou.
    An outlined card: one row per pending item the person may decide, with Refuse and Approve beside it.
--}}
<x-filament-widgets::widget class="md-dash-waiting">
    @php($items = $this->items())
    <section class="md-dash-panel md-dash-panel--outlined">
        <header class="md-dash-panel__header">
            <h2 class="md-dash-panel__title">{{ __('Waiting for you') }}</h2>
            @if ($url = $this->viewAllUrl())
                <a href="{{ $url }}" class="md-dash-panel__link" wire:navigate>{{ __('View all') }}</a>
            @endif
        </header>

        @forelse ($items as $item)
            <div class="md-dash-item" wire:key="waiting-{{ $item['key'] }}">
                <span class="md-dash-avatar" aria-hidden="true">{{ $item['initials'] }}</span>
                <div class="md-dash-item__text">
                    <span class="md-dash-item__title">{{ $item['title'] }}</span>
                    <span class="md-dash-item__meta">{{ $item['meta'] }}</span>
                </div>
                <div class="md-dash-item__actions">
                    {{ ($this->refuseAction)(['key' => $item['key']]) }}
                    {{ ($this->approveAction)(['key' => $item['key']]) }}
                </div>
            </div>
        @empty
            <p class="md-dash-empty">{{ __('Nothing is waiting for you.') }}</p>
        @endforelse
    </section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
