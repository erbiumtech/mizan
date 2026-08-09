<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">Expected columns</x-slot>
        <x-slot name="description">
            Order does not matter — the header is used to find them. Extra columns are ignored.
        </x-slot>

        <div class="flex flex-wrap gap-2">
            @foreach($this->columnsFor() as $column)
                <x-filament::badge :color="$loop->first ? 'warning' : 'gray'">
                    {{ $column }}{{ $loop->first ? ' (required)' : '' }}
                </x-filament::badge>
            @endforeach
        </div>
    </x-filament::section>

    @if($preview)
        <x-filament::section>
            <x-slot name="heading">What this file would do</x-slot>
            <x-slot name="afterHeader">
                <x-filament::badge color="success">{{ $preview['ready'] }} ready</x-filament::badge>
                @if($preview['skipped'] > 0)
                    <x-filament::badge color="danger">{{ $preview['skipped'] }} skipped</x-filament::badge>
                @endif
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Line</th>
                            @foreach($this->columnsFor() as $column)
                                <th class="py-2 pr-4 font-medium">{{ $column }}</th>
                            @endforeach
                            <th class="py-2 pr-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($preview['rows'], 0, 50) as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4 text-gray-400">{{ $row['_line'] }}</td>
                                @foreach($this->columnsFor() as $column)
                                    <td class="py-1.5 pr-4">{{ $row[$column] ?: '—' }}</td>
                                @endforeach
                                <td class="py-1.5 pr-4">
                                    @if($row['_problem'])
                                        <span class="text-danger-600 dark:text-danger-400">{{ $row['_problem'] }}</span>
                                    @else
                                        <span class="text-success-600 dark:text-success-400">ready</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if(count($preview['rows']) > 50)
                <p class="mt-3 text-sm text-gray-500">
                    Showing the first 50 of {{ count($preview['rows']) }} rows. All of them will be imported.
                </p>
            @endif

            @if($preview['skipped'] > 0)
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    Rows with a problem are named and skipped rather than stopping the import — a typo on one line
                    should not cost the others. Fix them and import the file again; clients and products are matched
                    by name and SKU, so a second run corrects rather than duplicates.
                </p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
