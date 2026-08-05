<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())
    @php($rows = $this->rows())
    @php($money = fn ($amount) => number_format((float) $amount, 2))
    @php($labels = ['current' => 'Current', '31-60' => '31–60 days', '61-90' => '61–90 days', '90+' => '90+ days'])

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach($labels as $bucket => $label)
            <x-reports.stat-card
                :label="$label"
                :value="$money($report['buckets'][$bucket] ?? 0)"
                {{-- Only the oldest bucket is coloured: everything ages, and a page
                     that shouts about all of it says nothing about any of it. --}}
                :color="$bucket === '90+' && ($report['buckets'][$bucket] ?? 0) > 0 ? 'danger' : 'gray'"
            />
        @endforeach

        <x-reports.stat-card
            label="Total outstanding"
            :value="$money($report['total'])"
            color="primary"
            :help="count($rows) . ' ' . \Illuminate\Support\Str::plural('invoice', count($rows))"
        />
    </div>

    <x-filament::section>
        <x-slot name="heading">
            {{ $this->isReceivable() ? 'Owed to us' : 'Owed by us' }},
            as of {{ \Carbon\Carbon::parse($report['as_of'])->format('d M Y') }}
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-white/10">
                        <th class="py-2 pr-4 font-medium">Invoice</th>
                        <th class="py-2 pr-4 font-medium">{{ $this->isReceivable() ? 'Customer' : 'Supplier' }}</th>
                        <th class="py-2 pr-4 font-medium">Age</th>
                        <th class="py-2 pl-4 text-right font-medium">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4 font-mono text-xs">{{ $row['invoice_number'] }}</td>
                            <td class="py-1.5 pr-4">{{ $row['contact'] }}</td>
                            <td class="py-1.5 pr-4">
                                <x-filament::badge :color="$row['bucket'] === '90+' ? 'danger' : ($row['bucket'] === 'current' ? 'gray' : 'warning')">
                                    {{ $labels[$row['bucket']] }}
                                </x-filament::badge>
                                @if($row['days_overdue'] > 0)
                                    <span class="ml-1 text-gray-400">{{ $row['days_overdue'] }} days overdue</span>
                                @endif
                            </td>
                            {{-- Billed in its own currency; the total below adds up in the company's. --}}
                            <td class="py-1.5 pl-4 text-right tabular-nums">
                                {{ $money($row['outstanding']) }}
                                @if(($row['currency_code'] ?? null) && $row['currency_code'] !== \App\Modules\Accounting\Models\Currency::baseCode())
                                    <span class="block text-xs text-gray-400">
                                        {{ $row['currency_code'] }} — {{ $money($row['outstanding_base']) }}
                                        {{ \App\Modules\Accounting\Models\Currency::baseCode() }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-gray-400">
                                Nothing outstanding — every issued invoice is paid.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($rows !== [])
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                            <td colspan="3" class="py-2 pr-4">Total in {{ \App\Modules\Accounting\Models\Currency::baseCode() }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ $money($report['total']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
