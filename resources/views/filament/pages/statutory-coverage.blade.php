{{--
    Statutory coverage — docs/saas-launch-plan.md §14.10.

    Grouped by jurisdiction, with the "not a compliance claim" paragraph first and not
    collapsible. Somebody quoting this page in a tender should have read it.
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">What this page is, and is not</x-slot>

        <p class="text-sm">
            Every row below says <strong>what this software does</strong> about a law. None of them says
            that your company complies with it. Compliance is a statement about your affairs — your
            contracts, your filings, your records — and no software can make it on your behalf.
        </p>
        <p class="text-sm mt-2">
            Rates and thresholds change with each Finance Act and each provincial amendment. Where the
            product holds them as data, they are yours to keep current, and the screen that holds them is
            named in the row.
        </p>
    </x-filament::section>

    @foreach(collect($this->entries())->groupBy('jurisdiction') as $jurisdiction => $entries)
        <x-filament::section>
            <x-slot name="heading">{{ $jurisdiction }}</x-slot>

            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach($entries as $entry)
                    <div class="py-3 first:pt-0 last:pb-0">
                        <div class="flex items-start justify-between gap-4">
                            <p class="text-sm font-medium">{{ $entry['act'] }}</p>

                            @if(! $entry['available'])
                                <span class="text-xs rounded px-2 py-0.5 bg-gray-100 dark:bg-white/10 whitespace-nowrap">
                                    Needs the {{ $entry['module'] }} module
                                </span>
                            @endif
                        </div>

                        <p class="text-sm mt-1">{{ $entry['does'] }}</p>

                        @if($entry['where'])
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $entry['where'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach

    @if($this->entries() === [])
        <x-filament::section>
            <p class="text-sm">Nothing is registered. That means no module that implements a statutory
                mechanism is installed.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
