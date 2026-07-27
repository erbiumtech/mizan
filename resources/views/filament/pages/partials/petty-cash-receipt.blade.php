@php($url = $voucher?->receiptUrl())

<div class="space-y-3">
    @if(! $url)
        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
            The attachment for this voucher is missing from storage.
        </p>
    @elseif($voucher->receiptIsPdf())
        <iframe
            src="{{ $url }}"
            title="Attachment for {{ $voucher->voucher_no }}"
            class="h-[70vh] w-full rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10"
        ></iframe>
    @else
        <img
            src="{{ $url }}"
            alt="Attachment for {{ $voucher->voucher_no }}"
            class="mx-auto max-h-[70vh] w-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10"
        >
    @endif

    @if($url)
        <div class="flex justify-end">
            <x-filament::link :href="$url" target="_blank" icon="heroicon-m-arrow-top-right-on-square">
                Open in new tab
            </x-filament::link>
        </div>
    @endif
</div>
