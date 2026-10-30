{{--
    My email signature — docs/saas-launch-plan.md §14.10.

    Preview, then the markup to copy. No copy button that needs JavaScript: a textarea
    somebody selects works in every browser and in the one place this matters, which is
    a locked-down office desktop.
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">How it looks</x-slot>
        <x-slot name="description">Built from your employee record and the company letterhead. Change either and this changes with it.</x-slot>

        <div class="rounded-lg border bg-white p-4 overflow-x-auto">
            {!! $this->preview() !!}
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Paste this into your mail client</x-slot>
        <x-slot name="description">
            Outlook: File → Options → Mail → Signatures. Gmail: Settings → General → Signature, then paste the
            preview above rather than this markup — Gmail takes the formatted version, not the HTML.
        </x-slot>

        <textarea
            readonly
            rows="12"
            onclick="this.select()"
            class="w-full rounded-lg border p-3 font-mono text-xs"
        >{{ $this->markup() }}</textarea>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Plain text</x-slot>
        <x-slot name="description">For a client that refuses HTML, and for the bottom of a reply.</x-slot>

        <textarea
            readonly
            rows="6"
            onclick="this.select()"
            class="w-full rounded-lg border p-3 font-mono text-xs"
        >{{ $this->plain() }}</textarea>
    </x-filament::section>
</x-filament-panels::page>
