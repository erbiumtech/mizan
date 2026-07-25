<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">GnuCash Import</x-slot>
        <x-slot name="description">Import GnuCash CSV exports — Account Tree, Transactions, or Active Register (auto-detected)</x-slot>

        @if($result)
            <div class="rounded-lg bg-success-50 dark:bg-success-500/10 p-4 mb-6">
                <p class="font-semibold text-success-700 dark:text-success-400">Import complete ({{ $result['kind'] }})</p>
                <ul class="text-sm text-success-700 dark:text-success-400 mt-2 ml-5 list-disc">
                    @foreach($result as $key => $value)
                        @if(! in_array($key, ['kind', 'errors', 'dry_run']))
                            <li>{{ str_replace('_', ' ', $key) }}: {{ is_array($value) ? count($value) : $value }}</li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button wire:click="preview" icon="heroicon-o-magnifying-glass">
                Upload &amp; Preview
            </x-filament::button>
        </div>
    </x-filament::section>

    @if($preview)
        <x-filament::section>
            <x-slot name="heading">Dry-run preview ({{ $preview['kind'] }}) — nothing written yet</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-2 pr-4 font-medium">Metric</th>
                            <th class="py-2 pl-4 font-medium text-right">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($preview as $key => $value)
                            @if(! in_array($key, ['kind', 'errors', 'dry_run']))
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-4">{{ ucfirst(str_replace('_', ' ', $key)) }}</td>
                                    <td class="py-1.5 pl-4 text-right tabular-nums">{{ is_array($value) ? count($value) : $value }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if(! empty($preview['errors']))
                <p class="mt-4 font-semibold text-danger-600 dark:text-danger-400">{{ count($preview['errors']) }} row(s) will be skipped:</p>
                <ul class="text-sm text-danger-600 dark:text-danger-400 mt-1 ml-5 list-disc">
                    @foreach(array_slice($preview['errors'], 0, 20) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                    @if(count($preview['errors']) > 20)
                        <li>… and {{ count($preview['errors']) - 20 }} more</li>
                    @endif
                </ul>
            @endif

            <div class="mt-6">
                <x-filament::button
                    wire:click="confirm"
                    color="primary"
                    icon="heroicon-o-check"
                    wire:confirm="Write these changes to the ledger?">
                    Confirm Import
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
