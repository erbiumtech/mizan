<x-filament-widgets::widget>
    @php($b = $this->badges())
    @if($b['earned']->isNotEmpty() || $b['locked']->isNotEmpty())
        <x-filament::section heading="My badges" icon="heroicon-o-trophy">
            <div class="flex flex-wrap gap-2">
                @foreach($b['earned'] as $award)
                    <x-filament::badge color="success" :icon="$award->badge?->icon ?: 'heroicon-o-star'">
                        {{ $award->badge?->name }} · {{ $award->awarded_on->format('d M Y') }}
                    </x-filament::badge>
                @endforeach
                @foreach($b['locked'] as $badge)
                    <x-filament::badge color="gray" icon="heroicon-o-lock-closed" :tooltip="$badge->description">
                        {{ $badge->name }}
                    </x-filament::badge>
                @endforeach
            </div>
            @if($b['locked']->isNotEmpty())
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Locked: {{ $b['locked']->map(fn ($badge) => $badge->name.($badge->description ? ' — '.$badge->description : ''))->implode('; ') }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
