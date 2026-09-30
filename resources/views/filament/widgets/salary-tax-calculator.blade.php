@php($result = $this->compute())

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Salary Tax Calculator</x-slot>
        <x-slot name="description">
            {{ $result['year'] ? "Tax year {$result['year']} slabs — the same calculation your payslip uses." : 'No active fiscal year.' }}
        </x-slot>

        <div class="flex flex-col gap-4">
            <label class="block">
                <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Monthly gross salary (PKR)</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input
                        type="text"
                        inputmode="numeric"
                        wire:model.live.debounce.400ms="monthly"
                        placeholder="e.g. 250,000"
                    />
                </x-filament::input.wrapper>
            </label>

            @if ($result['no_slabs'])
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
                    No salary slabs are seeded for this year, so this cannot be worked out —
                    "zero tax" would be a wrong answer, not a nice one.
                </p>
            @elseif ($result['monthly'] > 0)
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Monthly tax</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($result['monthly_tax'], 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Take-home / month</p>
                        <p class="mt-1 text-lg font-bold tabular-nums text-primary-600 dark:text-primary-400">{{ number_format($result['take_home'], 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Annual tax</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($result['annual_tax'], 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Effective rate</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($result['effective_rate'], 2) }}%</p>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Type a monthly salary to see the tax, the take-home and the effective rate.
                </p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
