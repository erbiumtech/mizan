<x-filament-panels::page>
    @php($grid = $this->grid())
    <x-filament::section :heading="\Illuminate\Support\Carbon::parse($this->month.'-01')->format('F Y')">
        <div class="overflow-x-auto">
            <table class="text-xs w-full border-collapse">
                <thead>
                    <tr>
                        <th class="text-left p-1 sticky left-0 bg-white dark:bg-gray-900">Employee</th>
                        @foreach($grid['days'] as $day)
                            <th class="p-1 text-center font-normal {{ $day->isWeekend() ? 'text-gray-400' : '' }}">{{ $day->day }}<br><span class="text-[10px]">{{ $day->format('D')[0] }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($grid['rows'] as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="p-1 whitespace-nowrap sticky left-0 bg-white dark:bg-gray-900">{{ $row['employee'] }}</td>
                            @foreach($grid['days'] as $day)
                                @php($cell = $row['cells'][$day->toDateString()] ?? null)
                                <td class="p-0.5 text-center">
                                    @if($cell)
                                        <span class="inline-block w-7 rounded text-white" style="background: {{ $cell['colour'] }}" title="{{ $day->toFormattedDateString() }}">{{ $cell['label'] }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($grid['days']) + 1 }}" class="p-3 text-gray-500">Nobody is on approved leave this month.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
