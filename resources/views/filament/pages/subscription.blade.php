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
                    @elseif($s['plan'] === 'No plan')
                        Licensed by hand; nothing to subscribe to
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

    @if($s['customDomainAllowed'])
        <x-filament::section heading="Your domain">
            @if($s['domain'])
                <p>Your panel answers on <span class="font-medium">https://{{ $s['domain'] }}</span>. Sign in there and the login page carries your logo.</p>
            @else
                <p>Your plan includes a custom domain. Create a CNAME record <span class="font-mono">hr.yourcompany.pk → {{ $s['cnameTarget'] }}</span>, then save it with <span class="font-medium">Custom domain</span> above.</p>
            @endif
        </x-filament::section>
    @endif

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

        {{--
            Snapshots — docs/saas-launch-plan.md §14.8. Listed, never linked: an archive
            is the whole database in one encrypted file and restoring one is an
            operator's job. The question this answers is whether there is a recent one.
        --}}
        <div class="mt-4">
            <p class="text-sm font-medium">Database snapshots</p>

            @if($s['snapshots'] === [])
                <p class="text-sm mt-1">None yet. One is taken every night, and "Back up now" above takes one immediately.</p>
            @else
                <ul class="text-sm mt-1 space-y-1">
                    @foreach($s['snapshots'] as $snapshot)
                        <li>{{ $snapshot['at'] }} — {{ $snapshot['size'] }}</li>
                    @endforeach
                </ul>
                <p class="text-sm mt-2">
                    Kept on the server and not downloadable from here: they are encrypted with a key held by
                    whoever runs the installation, and restoring one is their job. For a copy you keep yourself,
                    use "Export my data".
                </p>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section heading="How billing works">
        <p class="text-sm">
            Only active employees count, as of the 1st of each month. Invoices are emailed to the billing address and paid by bank transfer
            @if($s['operator']) to {{ $s['operator'] }}@endif. A Pakistani address is billed monthly in PKR; any other quarterly in advance, in USD or EUR.
        </p>
    </x-filament::section>
</x-filament-panels::page>
