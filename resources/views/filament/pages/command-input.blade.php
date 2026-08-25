{{--
    The dashboard's command box — docs/ai-command-bot-plan.md §8.3.

    **An on-ramp, not a second bot.** It collects a sentence and hands it to the ⌘/ bar, which does the
    interpreting, writes the one audit row, and holds the confirmation gate. Nothing here talks to a
    resolver. A box that did its own interpreting would be a second place for §2's sign rules to live, and
    the whole point of §2.1 is that there is one.

    It exists because the bar had a discovery problem that a button alone does not solve: knowing *that*
    you can type a transaction is different from knowing *what* to type. An empty box with a worked example
    in its placeholder, sitting where people already start their day, answers the second question.
--}}
@if (\App\Filament\Livewire\CommandBar::available())
    <div
        x-data="{ text: '' }"
        class="flex items-center gap-2 rounded-xl bg-white px-3 py-1.5 ring-1 ring-gray-950/10 transition focus-within:ring-2 focus-within:ring-primary-600 dark:bg-white/5 dark:ring-white/10"
    >
        <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
        </svg>

        {{--
            Enter hands over and clears. It does NOT book anything — the bar opens with the proposal and
            waits, exactly as if it had been typed there. §6's gate is not something a second surface gets
            to skip, and a dashboard box that posted on Enter would be the one place in the feature where
            Enter moved money.
        --}}
        <input
            type="text"
            x-model="text"
            @keydown.enter.prevent="if (text.trim()) { window.dispatchEvent(new CustomEvent('open-command-bar', { detail: { text: text.trim() } })); text = '' }"
            placeholder="Type a transaction — e.g. “rent 25000 out”, “income 500000 in”, “بجلی ۴۵۰۰ دیا”"
            aria-label="Type a transaction"
            autocomplete="off"
            class="w-full border-0 bg-transparent py-1.5 text-sm text-gray-950 placeholder:text-gray-400 focus:outline-none focus:ring-0 dark:text-white dark:placeholder:text-gray-500"
        >

        <kbd class="hidden shrink-0 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-sans text-xs font-medium text-gray-400 sm:inline-block dark:border-white/10 dark:bg-white/5 dark:text-gray-500">
            ⌘/
        </kbd>
    </div>
@endif
