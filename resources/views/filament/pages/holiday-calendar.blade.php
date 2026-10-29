<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($this->months() as $month)
            <x-filament::section :heading="$month['name'].' '.$this->year" compact>
                <div class="grid grid-cols-7 gap-1 text-xs text-center">
                    @foreach(['M','T','W','T','F','S','S'] as $d)<div class="text-gray-400">{{ $d }}</div>@endforeach
                    @for($i = 1; $i < $month['days'][0]['date']->dayOfWeekIso; $i++)<div></div>@endfor
                    @foreach($month['days'] as $day)
                        @php($h = $day['holiday'])
                        <div class="rounded p-1 {{ $h ? ($h->kind === 'public' ? 'bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-100' : 'bg-warning-100 text-warning-800 dark:bg-warning-900 dark:text-warning-100') : ($day['date']->isWeekend() ? 'text-gray-400' : '') }}" @if($h) title="{{ $h->name }} ({{ $h->kind }})" @endif>{{ $day['date']->day }}</div>
                    @endforeach
                </div>
                @php($list = collect($month['days'])->pluck('holiday')->filter())
                @if($list->isNotEmpty())
                    <ul class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                        @foreach($list as $h)<li>{{ $h->date->format('d') }} — {{ $h->name }}@if($h->kind !== 'public') ({{ $h->kind }})@endif</li>@endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
