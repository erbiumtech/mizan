{{--
    The topbar trigger for the ⌘J command bar — docs/ai-command-bot-plan.md §8.

    **This exists because the hotkey alone did not work.** ⌘J is a reserved browser shortcut on both
    macOS and Windows — Chrome and Firefox open Downloads with it, from the native menu bar, before the
    keystroke ever reaches the page — so `preventDefault()` in the component never runs and the dialog
    never opened. A feature whose only affordance is a key the browser owns is a feature nobody can reach,
    and it fails silently: nothing appears, and there is no error to look at.

    So the button is the primary way in and the hotkey is the shortcut, which is the right order anyway —
    the same order the ⌘K palette already uses next door.

    Rendered only when the bar itself will render, via the same predicate the component uses. A trigger
    that opens nothing is the failure this replaced, in the other direction.
--}}
@if (\App\Filament\Livewire\CommandBar::available())
    <button
        type="button"
        onclick="window.dispatchEvent(new CustomEvent('open-command-bar'))"
        title="Type a transaction (⌘/)"
        aria-label="Type a transaction"
        class="flex items-center justify-center rounded-lg p-2 text-gray-400 transition hover:bg-gray-50 hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-primary-400"
    >
        {{-- The same bolt the dialog opens with, so the button and what it opens are recognisably one thing. --}}
        <x-filament::icon icon="heroicon-m-bolt" class="h-5 w-5" />
    </button>
@endif
