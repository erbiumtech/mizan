<x-filament-widgets::widget>
    @php($announcements = $this->announcements())
    @if($announcements->isNotEmpty())
        <x-filament::section heading="Announcements" icon="heroicon-o-megaphone">
            <ul class="space-y-3">
                @foreach($announcements as $announcement)
                    <li>
                        <div class="flex items-baseline gap-2">
                            @if($announcement->pinned)
                                <x-filament::badge color="warning" size="sm">Pinned</x-filament::badge>
                            @endif
                            <span class="font-medium">{{ $announcement->title }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $announcement->published_at?->diffForHumans() }} · {{ $announcement->audienceLabel() }}</span>
                        </div>
                        <p class="mt-1 text-sm whitespace-pre-line">{{ $announcement->body }}</p>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
