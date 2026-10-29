<x-filament-panels::page>
    @php($s = $this->summary())

    <x-filament::section heading="Your plan">
        <dl class="grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Plan</dt>
                <dd class="font-medium">{{ $s['plan'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                <dd class="font-medium">
                    @if($s['subscribed'])
                        Subscribed, billed in {{ $s['currency'] }}
                    @elseif($s['trialEndsAt'])
                        Trial until {{ $s['trialEndsAt'] }}
                    @else
                        Not subscribed
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Active employees</dt>
                <dd class="font-medium">
                    {{ $s['seats'] ?? '—' }}
                    @if($s['countedAt'])<span class="text-sm text-gray-500 dark:text-gray-400">counted {{ $s['countedAt'] }}</span>@endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Price</dt>
                <dd class="font-medium">
                    @if($s['price'] !== null)
                        {{ $s['currency'] }} {{ number_format($s['price'], 2) }} per active employee per month, minimum {{ $s['minimumSeats'] }}
                    @else
                        Per active employee per month, minimum {{ $s['minimumSeats'] }}; the currency follows your billing address
                    @endif
                </dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section heading="Your data">
        <p class="text-sm">
            @if($s['export'])
                Latest export: <a class="underline" href="{{ $s['export']['url'] }}">{{ basename($s['export']['path']) }}</a>,
                made {{ $s['export']['at'] }}, {{ $s['export']['bytes'] >= 1048576 ? round($s['export']['bytes'] / 1048576, 1).' MB' : round($s['export']['bytes'] / 1024).' KB' }}.
                Administrators of this company can download it; it does not expire.
            @else
                No export yet. "Export my data" above builds one: the whole database as SQL and every uploaded file, in one archive you keep.
            @endif
        </p>
    </x-filament::section>

    <x-filament::section heading="How billing works">
        <p class="text-sm">
            Only active employees count, as of the 1st of each month. Invoices are emailed to the billing address and paid by bank transfer
            @if($s['operator']) to {{ $s['operator'] }}@endif. A Pakistani address is billed monthly in PKR; any other quarterly in advance, in USD or EUR.
        </p>
    </x-filament::section>
</x-filament-panels::page>
