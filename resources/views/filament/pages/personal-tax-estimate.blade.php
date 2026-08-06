@php($result = $this->getEstimate())
@php($estimate = $result['estimate'])

<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($result['error'])
        {{-- Shown rather than swallowed: "no schedule seeded" and "you owe
             nothing" have to look different, or a missing schedule reads as
             good news. --}}
        <x-filament::section>
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
                This cannot be worked out yet
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $result['error'] }}</p>
        </x-filament::section>
    @elseif ($estimate)
        <x-filament::section>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Income counted</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        PKR {{ number_format($estimate['total_income'], 2) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Estimated tax</p>
                    <p class="mt-1 text-2xl font-bold text-primary-600 dark:text-primary-400">
                        PKR {{ number_format($estimate['total_tax'], 2) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Overall rate</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        @if ($estimate['total_income'] > 0)
                            {{ number_format($estimate['total_tax'] / $estimate['total_income'] * 100, 2) }}%
                        @else
                            —
                        @endif
                    </p>
                </div>
            </div>

            @if ($result['filer_status'])
                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Recorded as {{ $result['filer_status'] === 'filer' ? 'a filer' : 'a non-filer' }}.
                    Filer status changes the rates at which tax is withheld from you, not the amount the
                    brackets produce, so it does not alter the figure above.
                </p>
            @endif
        </x-filament::section>

        @if ($estimate['regimes'] !== [])
            <x-filament::section heading="How that was worked out">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 font-medium">Taxed as</th>
                                <th class="py-2 text-right font-medium">Income</th>
                                <th class="py-2 font-medium">Bracket</th>
                                <th class="py-2 text-right font-medium">Rate on excess</th>
                                <th class="py-2 text-right font-medium">Tax</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($estimate['regimes'] as $row)
                                <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                    <td class="py-2 text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                                    <td class="py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                        {{ number_format($row['income'], 2) }}
                                    </td>
                                    <td class="py-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['bracket']?->label() ?? '—' }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ number_format($row['marginal_rate'], 2) }}%
                                    </td>
                                    <td class="py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                                        {{ number_format($row['tax'], 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($estimate['unclassified'] > 0)
            <x-filament::section>
                <p class="text-sm font-medium text-warning-600 dark:text-warning-400">
                    PKR {{ number_format($estimate['unclassified'], 2) }} of income is not counted above
                </p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    It sits on income accounts with no <strong>Taxed as</strong> setting. Guessing how it is
                    taxed would give you a confident but wrong number, so it is left out. Set
                    <strong>Taxed as</strong> on those accounts under My Accounts and it will be included.
                </p>
            </x-filament::section>
        @endif
    @endif

    <x-filament::section>
        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
            This is an estimate, not tax advice
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-500 dark:text-gray-400">
            <li>It works only from what you have recorded here.</li>
            <li>It does not know about tax already deducted from you at source, including by your employer.</li>
            <li>It does not apply tax credits, deductible allowances, or the surcharge on high salaried income.</li>
            <li>Rental and capital gains use indicative flat rates, not the full schedules, which depend on the asset and how long it was held.</li>
            <li>Each kind of income is assessed on its own schedule and the results added, which is a simplification of a real return.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
