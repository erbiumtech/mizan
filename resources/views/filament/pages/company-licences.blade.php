<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">What licensed does and does not mean</x-slot>

        <div class="space-y-2 text-sm text-gray-500 dark:text-gray-400">
            <p>
                <strong>Licensed</strong> is your grant: the company has bought the module. <strong>Enabled</strong>
                is theirs — what they want visible right now, set by their own Administrator under Company
                Settings → Modules. A module is available only when both are true.
            </p>
            <p>
                Revoking a licence deliberately leaves their switch alone, so re-granting it restores the choice
                they made rather than silently resetting it.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
