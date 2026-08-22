{{--
    Importing a programme — docs/construction-management-plan.md §13.

    The page is one form and one button on purpose. The only decision on it that carries consequences is update
    against baseline, and the note below states the reason in the words §13 uses: a baseline that moves with the plan
    retires the delays that moved it.
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Store the programme; the scheduling stays in P6</x-slot>

        <x-slot name="description">
            Activities, their logical links, progress, float and criticality are read from the file and stored as they
            are. Nothing here calculates a date — float and the critical path are the exporting tool's figures, and the
            register prints which tool they came from.
        </x-slot>

        <form wire:submit="import" class="space-y-6">
            {{ $this->form }}

            <div class="flex items-center gap-3">
                <x-filament::button type="submit" wire:loading.attr="disabled">
                    Import
                </x-filament::button>

                <span class="text-sm text-gray-500 dark:text-gray-400" wire:loading wire:target="import">
                    Reading the file…
                </span>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
