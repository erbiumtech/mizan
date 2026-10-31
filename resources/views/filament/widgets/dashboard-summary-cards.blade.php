{{--
    The Material dashboard's summary row — App\Modules\Core\Filament\Widgets\DashboardSummaryCards.
    Each card is a tonal container; the figures come from the modules (App\Support\DashboardSummaries).
--}}
<x-filament-widgets::widget class="md-dash-summary">
    <div class="md-dash-cards">
        @foreach ($this->cards() as $card)
            <section class="md-dash-card md-dash-card--{{ $card['tone'] }}" wire:key="summary-{{ $card['key'] }}">
                <h2 class="md-dash-card__label">{{ $card['label'] }}</h2>
                <p class="md-dash-card__value">{{ $card['value'] }}</p>
                @if (filled($card['url']) && filled($card['link']))
                    <a href="{{ $card['url'] }}" class="md-dash-card__link" wire:navigate>{{ $card['link'] }}</a>
                @endif
            </section>
        @endforeach
    </div>
</x-filament-widgets::widget>
