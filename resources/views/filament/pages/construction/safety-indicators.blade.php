<x-filament-panels::page>
    {{ $this->form }}

    @php
        $exposure = $this->exposure();
        $rates = $this->rates();
        $ratio = $this->nearMissRatio();
        $leading = $this->leading();

        // Every leading indicator that can legitimately be unknown comes back null rather than zero, and prints this.
        // §17.6's whole argument in one helper: a percentage of nothing is not nought per cent.
        $pc = fn (?float $v) => $v === null ? 'No data' : number_format($v, 1) . '%';
        $num = fn (?int $v) => $v === null ? 'No data' : number_format($v);
    @endphp

    @if (! $this->selectedJob())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Choose a job.</p>
        </x-filament::section>
    @else
        {{-- The denominator first, and on its own.

             When it is missing this is the only thing on the page worth reading and everything below it is a refusal,
             so it is not a footnote under the figures — §17.6: "the page must say insufficient exposure data and refuse
             to render a rate." --}}
        <x-filament::section heading="Exposure hours">
            @if ($exposure->isAvailable())
                <p class="text-2xl font-semibold tabular-nums">{{ number_format($exposure->hours, 0) }}</p>
                {{-- Which source, printed, because counting the diary and Timesheets together halves every rate below
                     and a halved rate looks like a number somebody can act on. --}}
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">From {{ $exposure->source }}.</p>
            @else
                <p class="text-lg font-semibold text-danger-600 dark:text-danger-400">Insufficient exposure data</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $exposure->reason }}</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Lagging indicators">
            {{-- The base on the face of the section as well as on every row: §17.6's factor-of-five complaint is about
                 a figure travelling without it, and a heading somebody screenshots is how it travels. --}}
            <x-slot name="description">
                Computed live and never stored — a first-aid case becomes a lost-time case the day somebody does not come
                back, and a stored rate would still be reporting the old classification. All rates
                {{ $rates !== [] ? $rates[0]->baseLabel() : '' }}.
            </x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rates as $rate)
                    <div class="flex flex-wrap items-baseline justify-between gap-2 py-3">
                        <div>
                            <p class="text-sm font-medium">{{ $rate->label }}</p>
                            @if ($rate->isAvailable())
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $rate->numerator }} {{ $rate->numeratorLabel }} over
                                    {{ number_format($rate->exposure->hours, 0) }} hours
                                </p>
                            @else
                                <p class="text-xs text-gray-600 dark:text-gray-300">{{ $rate->reason }}</p>
                            @endif
                        </div>

                        @if ($rate->isAvailable())
                            <p class="text-right">
                                <span class="text-xl font-semibold tabular-nums">{{ $rate->display() }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $rate->baseLabel() }}</span>
                            </p>
                        @else
                            {{-- Not a dash and not a zero. A dash in a numeric column reads as nothing happened; this
                                 means nobody knows. --}}
                            <p class="text-right text-sm font-medium text-danger-600 dark:text-danger-400">
                                {{ $rate->display() }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if ($ratio)
            <x-filament::section heading="Near-miss ratio">
                <x-slot name="description">
                    Needs no exposure hours, so it survives the absence that silences the rates above.
                </x-slot>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Near misses and unsafe conditions</p>
                        <p class="text-lg font-semibold tabular-nums">{{ number_format($ratio['near_misses']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Lost-time injuries</p>
                        <p class="text-lg font-semibold tabular-nums">{{ number_format($ratio['lost_time']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Near misses per lost-time injury</p>
                        <p class="text-lg font-semibold tabular-nums">
                            {{-- No lost-time injury is the *good* state and must not read as a bad ratio. --}}
                            {{ $ratio['ratio'] === null ? 'No lost-time injury to divide by' : number_format($ratio['ratio'], 1) }}
                        </p>
                    </div>
                </div>

                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    A large number here is a site where people report things. A small one is a site where they do not.
                </p>
            </x-filament::section>
        @endif

        @if ($leading !== [])
            <x-filament::section heading="Leading indicators">
                <x-slot name="description">
                    What can still be changed. None of these needs a denominator, which is why they are on the same page.
                </x-slot>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ([
                        'Near-miss reports' => $num($leading['near_miss_reports']),
                        'Toolbox talks delivered' => $num($leading['toolbox_talks']),
                        'Attendances recorded' => $num($leading['toolbox_attendances']),
                        'Average attendance' => $leading['toolbox_average_attendance'] === null ? 'No talks' : number_format($leading['toolbox_average_attendance'], 1),
                        'Inspections completed' => $num($leading['inspections_completed']),
                        'Hold and witness points planned' => $leading['inspections_planned'] === null ? 'No plan in force' : number_format($leading['inspections_planned']),
                        'Permits closed on time' => $pc($leading['permits_closed_on_time_percent']),
                        'Overdue actions' => $num($leading['overdue_actions']),
                        'Induction coverage' => $pc($leading['induction_coverage_percent']),
                        'On site' => $num($leading['induction_on_site']),
                        'Hold points passed first time' => $pc($leading['hold_points_first_time_percent']),
                    ] as $label => $value)
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="text-lg font-semibold tabular-nums">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                @if (($leading['toolbox_unrecorded'] ?? 0) > 0)
                    {{-- A talk with no attendees is a talk that was recorded and not given, or given and not recorded.
                         Either way the attendance figures above are understated and the page says so rather than
                         letting somebody read them as complete. --}}
                    <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                        {{ $leading['toolbox_unrecorded'] }} talk(s) in this period have no attendees recorded, so the
                        attendance figures above are lower than what happened on site.
                    </p>
                @endif

                @if ($leading['inspections_planned'] === null)
                    <p class="mt-2 text-sm text-warning-600 dark:text-warning-400">
                        No inspection and test plan is in force for this job, so "inspections completed" is a count and
                        not a proportion. A completed count against no plan cannot be read as coverage.
                    </p>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
