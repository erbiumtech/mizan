{{--
    The ⌘J command bar — docs/ai-command-bot-plan.md §6.

    Two beats, and the second one is the feature. Typing sends the sentence to be interpreted; what comes
    back is a *proposal*, and nothing is booked until Confirm is pressed. That is why this is not a palette
    provider: the palette navigates on Enter, and here Enter must never be the last thing that happens
    before money moves.

    Styles are self-contained (`cb-*`) rather than borrowed from the palette's `cp-*` block. Both render
    through the same hook so borrowing would work today, and would break silently the moment somebody
    disabled one of them.
--}}
<div
    x-data="{
        open: false,
        openBar() { this.open = true; this.$nextTick(() => this.$refs.dialog.showModal()); this.$nextTick(() => this.$refs.input?.focus()) },
        close() { this.stopDictation(); this.open = false; this.$refs.dialog?.close(); $wire.cancel() },

        /* ---- voice (§5) ---------------------------------------------------
           The browser's own recogniser. Feature-detected rather than assumed:
           `supported` is false on Firefox and older Safari, and the mic simply
           does not render there — a dead button is worse than no button.

           The transcript is handed to the component and STOPS. It is never
           submitted for interpretation, because a mis-transcription that was
           acted on is indistinguishable afterwards from a mis-parse, and on a
           locale as thinly supported as ur-PK it is the likelier of the two.
        -------------------------------------------------------------------- */
        recogniser: null,
        listening: false,
        speechError: null,
        locale: @js(array_key_first($this->speechLocales())),
        supported: typeof window !== 'undefined' && !! (window.SpeechRecognition || window.webkitSpeechRecognition),

        toggleDictation() { this.listening ? this.stopDictation() : this.startDictation() },

        startDictation() {
            if (! this.supported) return;

            const Impl = window.SpeechRecognition || window.webkitSpeechRecognition;
            const r = new Impl();
            r.lang = this.locale;
            r.interimResults = true;
            r.continuous = false;
            r.maxAlternatives = 1;

            this.speechError = null;

            r.onresult = (e) => {
                let text = '';
                for (let i = 0; i < e.results.length; i++) text += e.results[i][0].transcript;

                if (e.results[e.results.length - 1].isFinal) {
                    /* Final only. Interim results are shown in the box for feedback but never
                       recorded as the transcript — half a sentence is not what was heard. */
                    $wire.dictated(text, this.locale);
                } else {
                    this.$refs.input.value = text;
                }
            };

            r.onerror = (e) => {
                this.speechError = ({
                    'not-allowed': 'Microphone permission was refused.',
                    'service-not-allowed': 'Microphone permission was refused.',
                    'no-speech': 'Nothing was heard.',
                    'audio-capture': 'No microphone was found.',
                    'language-not-supported': 'This browser cannot transcribe that language. Try English, or type it.',
                })[e.error] || 'Dictation failed. Type it instead.';
                this.listening = false;
            };

            r.onend = () => { this.listening = false; this.recogniser = null };

            this.recogniser = r;
            this.listening = true;
            r.start();
            this.$refs.input?.focus();
        },

        stopDictation() { try { this.recogniser?.stop() } catch (e) {} this.listening = false },
    }"
    {{--
        ⌘/ (ctrl+/ elsewhere), NOT ⌘J.

        ⌘J is a reserved browser shortcut on both platforms — Chrome and Firefox open Downloads with it,
        dispatched from the native menu bar before the keystroke reaches the document. `.prevent` runs too
        late to matter, so the bar never opened and there was nothing on screen or in the log to say why.

        Written as a guard rather than Alpine's key modifiers because "/" has no modifier name: `.slash`
        is not one of them, and `@keydown.window.meta./` is not a legal attribute. ⌘J is still honoured
        for anyone whose muscle memory has it and whose browser leaves it free (Safari does), but it is no
        longer the way in — the topbar button is.
    --}}
    @keydown.window="if (($event.metaKey || $event.ctrlKey) && ($event.key === '/' || $event.key === 'j')) { $event.preventDefault(); openBar() }"
    @open-command-bar.window="openBar()"
    @command-booked.window="close()"
>
    @if ($this->isAvailable())
        {{--
            `wire:ignore.self` is load bearing, and without it this dialog SHUTS THE MOMENT YOU PRESS ENTER.

            `showModal()` opens a <dialog> by setting an `open` ATTRIBUTE on the element. Interpreting is a
            Livewire round trip, and Livewire morphs the response over the live DOM — the server HTML has
            no `open` on it, so the morph dutifully removes the one the browser put there and the dialog
            closes. The proposal was rendered correctly into a box that was no longer on screen, which
            looks from the outside exactly like nothing happening.

            `.self` rather than plain `wire:ignore`: it maps to Alpine morph's `childrenOnly()`, so the
            element's own attributes are left alone while everything inside it still updates. Plain
            `wire:ignore` would also freeze the proposal — which is the only thing here that must change.
            The ⌘K palette next door gets away with plain `wire:ignore` because it renders its results from
            an Alpine array rather than from the server; this one cannot.

            Untestable from PHPUnit, which does not morph anything. The guard is that the attribute is
            asserted present — see AiCommandBotTest.
        --}}
        <dialog
            x-ref="dialog"
            wire:ignore.self
            class="cb-dialog"
            @keydown.esc.prevent="close()"
            @click="if ($event.target === $refs.dialog) close()"
        >
            <div class="cb-search">
                <svg class="cb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                </svg>

                <input
                    x-ref="input"
                    type="text"
                    wire:model="utterance"
                    wire:keydown.enter.prevent="interpret"
                    placeholder="rent 25000 out"
                    autocomplete="off"
                    aria-label="Type a transaction"
                >

                @if ($this->voiceEnabled())
                    {{-- Hidden entirely where the browser has no recogniser — a dead mic is worse than none. --}}
                    <template x-if="supported">
                        <div class="cb-voice">
                            @if (count($this->speechLocales()) > 1)
                                <select x-model="locale" class="cb-locale" aria-label="Dictation language">
                                    @foreach ($this->speechLocales() as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif

                            <button
                                type="button"
                                class="cb-mic"
                                :class="{ 'cb-mic-live': listening }"
                                @click="toggleDictation()"
                                :aria-pressed="listening"
                                :aria-label="listening ? 'Stop dictation' : 'Dictate a command'"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z" />
                                </svg>
                            </button>
                        </div>
                    </template>
                @endif

                <span class="cb-hint" wire:loading.remove wire:target="interpret">↵</span>
                <span class="cb-hint" wire:loading wire:target="interpret">…</span>
            </div>

            {{--
                Dictation feedback, above the proposal.

                A transcript is shown as something to READ AND EDIT, never as something already acted on
                (§5). The line below says so in as many words, because the difference between "here is what
                I heard" and "here is what I did" is the whole reason voice is a separate phase.
            --}}
            <template x-if="listening">
                <p class="cb-listening">Listening… speak the command, then check what appears before confirming.</p>
            </template>

            <template x-if="speechError">
                <p class="cb-speech-error" x-text="speechError"></p>
            </template>

            @if ($transcript && $transcript !== $utterance)
                <p class="cb-corrected">Heard: “{{ $transcript }}” — edited before submitting.</p>
            @endif

            @if ($preview)
                <div class="cb-body">
                    @if ($preview['error'] ?? null)
                        <p class="cb-error">{{ $preview['error'] }}</p>
                    @else
                        {{--
                            The most important string in the feature (§2.3). It states the EFFECT —
                            "money out — 25,000 leaves Cash / Bank" — never the word the user typed, so a
                            speaker using bank-statement English catches it here. This is the only place
                            they can.
                        --}}
                        <p class="cb-effect">{{ $preview['effect'] }}</p>

                        @if ($preview['detail'])
                            <p class="cb-detail">{{ $preview['detail'] }}</p>
                        @endif

                        @if ($preview['description'])
                            <p class="cb-memo">“{{ $preview['description'] }}”</p>
                        @endif

                        {{--
                            A question with a list beside it, rather than a question on its own.

                            The bar always preferred asking over guessing, and that half was right — but
                            the ask was a dead end. "What should this be filed under?" appeared with no
                            way to answer it, so the only route forward was to retype the whole command
                            using a word the alias table happened to know. From the outside that is
                            indistinguishable from the command being ignored, and it is how it was
                            reported: "income 500000 in — nothing happened."

                            Picking re-resolves through the resolver that asked, so a chosen value goes
                            through the same tenant lookup and the same rules as a parsed one.
                        --}}
                        @foreach ($preview['pickers'] ?? [] as $picker)
                            <label class="cb-pick">
                                <span class="cb-question">{{ $picker['question'] }}</span>

                                <select wire:model.live="answers.{{ $picker['slot'] }}" class="cb-select">
                                    <option value="">Choose…</option>
                                    @foreach ($picker['options'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach

                        @foreach ($preview['questions'] as $question)
                            <p class="cb-question">{{ $question }}</p>
                        @endforeach

                        @if ($preview['flags'])
                            <p class="cb-flag">
                                Guessed at: {{ implode(', ', array_map(fn ($f) => str_replace('transaction_type_code', 'category', $f), $preview['flags'])) }} — check before confirming.
                            </p>
                        @endif
                    @endif

                    <div class="cb-actions">
                        @if (($preview['complete'] ?? false) && ! ($preview['error'] ?? null))
                            {{--
                                Above the threshold, or with a flagged slot, the button carries the figure
                                and Enter does not reach it — §6's rule that the cost of being wrong scales
                                with the amount.
                            --}}
                            <button type="button" class="cb-confirm" wire:click="confirm">
                                {{ $preview['explicit'] ? 'Confirm '.$preview['effect'] : 'Confirm' }}
                            </button>
                        @endif

                        <button type="button" class="cb-cancel" @click="close()">Cancel</button>
                    </div>
                </div>
            @endif

            <div class="cb-footer">
                <span>Nothing is recorded until you confirm.</span>
                <span><kbd>⌘</kbd><kbd>/</kbd> open · <kbd>esc</kbd> close</span>
            </div>
        </dialog>
    @endif

    <style>
        .cb-dialog {
            position: fixed;
            inset: 12vh 0 auto 0;
            margin-inline: auto;
            width: min(560px, calc(100vw - 2rem));
            max-width: none;
            padding: 0;
            border: none;
            border-radius: 14px;
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 25px 50px -12px rgba(2, 6, 23, .35), 0 0 0 1px rgba(2, 6, 23, .05);
            overflow: hidden;
        }
        .cb-dialog::backdrop { background: rgba(2, 6, 23, .5); }

        .cb-search { display: flex; align-items: center; gap: .625rem; padding: 0 1rem; border-bottom: 1px solid #e5e7eb; }
        .cb-icon { width: 20px; height: 20px; color: #9ca3af; flex: none; }
        .cb-search input { flex: 1; border: 0; outline: none; background: transparent; padding: .875rem 0; font-size: 1rem; color: inherit; }
        .cb-search input::placeholder { color: #9ca3af; }
        .cb-hint { font-size: .75rem; color: #9ca3af; flex: none; }

        .cb-voice { display: flex; align-items: center; gap: .375rem; flex: none; }
        .cb-locale { border: 0; background: transparent; color: #9ca3af; font-size: .75rem; outline: none; cursor: pointer; }
        .cb-mic { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; padding: 0; border: 0; border-radius: 999px; background: transparent; color: #9ca3af; cursor: pointer; }
        .cb-mic svg { width: 18px; height: 18px; }
        .cb-mic:hover { color: #6b7280; }
        /* Live is a colour and a pulse, not a label: the input already shows the words arriving. */
        .cb-mic-live { color: #dc2626; animation: cb-pulse 1.2s ease-in-out infinite; }
        @keyframes cb-pulse { 0%, 100% { opacity: 1 } 50% { opacity: .45 } }

        .cb-listening { margin: 0; padding: .5rem 1rem; font-size: .75rem; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .cb-speech-error { margin: 0; padding: .5rem 1rem; font-size: .75rem; color: #b91c1c; border-bottom: 1px solid #e5e7eb; }
        .cb-corrected { margin: 0; padding: .5rem 1rem; font-size: .75rem; color: #9ca3af; font-style: italic; border-bottom: 1px solid #e5e7eb; }

        .cb-body { padding: 1rem; }
        .cb-effect { font-size: 1rem; font-weight: 700; margin: 0 0 .25rem; }
        .cb-detail { font-size: .8125rem; color: #6b7280; margin: 0 0 .25rem; }
        .cb-memo { font-size: .8125rem; color: #9ca3af; font-style: italic; margin: 0 0 .5rem; }
        .cb-question { font-size: .8125rem; color: #b45309; margin: .25rem 0; }
        .cb-pick { display: block; margin: .5rem 0; }
        .cb-select { width: 100%; margin-top: .25rem; padding: .4rem .5rem; border: 1px solid #e5e7eb; border-radius: 8px; background: transparent; color: inherit; font-size: .875rem; }
        .cb-flag { font-size: .8125rem; color: #b45309; margin: .5rem 0 0; }
        .cb-error { font-size: .8125rem; color: #b91c1c; margin: 0; }

        .cb-actions { display: flex; gap: .5rem; margin-top: .875rem; }
        {{--
            The palette variable is used BARE. Wrapping it in rgb() is what made this button invisible.

            Filament 5 defines its colours as complete values — `--primary-600: oklch(0.566 0.121
            147.083)` — not as the bare `R G B` channel triplets Filament 3 used. Wrapping one produces
            `rgb(oklch(...))`, which is invalid, so the browser drops the whole declaration. The button
            kept `color: #fff` and lost its background: white text on a white dialog. Rendered, clickable,
            and completely invisible — a worse failure than not rendering at all.

            The fallback did not save it either, and that is the part worth remembering: `rgb(var(--x, 217
            119 6))` reaches its fallback only when the variable is *undefined*. This one was defined and
            wrong-shaped, so the fallback never ran.

            A Blade comment rather than a CSS one so it does not ship on every panel page — and so the
            regression test can assert the broken idiom appears nowhere in the output.
        --}}
        .cb-confirm { flex: 1; padding: .5rem .75rem; border: 0; border-radius: 8px; background: var(--primary-600, #d97706); color: #fff; font-size: .875rem; font-weight: 600; cursor: pointer; }
        .cb-confirm:hover { background: var(--primary-700, #b45309); }
        .cb-cancel { padding: .5rem .75rem; border: 1px solid #e5e7eb; border-radius: 8px; background: transparent; color: #6b7280; font-size: .875rem; cursor: pointer; }

        .cb-footer { display: flex; justify-content: space-between; align-items: center; padding: .5rem 1rem; border-top: 1px solid #e5e7eb; font-size: .75rem; color: #9ca3af; }
        .cb-footer kbd { display: inline-block; min-width: 1.25rem; padding: .0625rem .25rem; margin-right: .25rem; border-radius: 4px; background: #f3f4f6; color: #6b7280; font-size: .6875rem; text-align: center; }

        .dark .cb-dialog { background: #18181b; color: #f4f4f5; box-shadow: 0 25px 50px -12px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.06); }
        .dark .cb-search, .dark .cb-footer,
        .dark .cb-listening, .dark .cb-speech-error, .dark .cb-corrected { border-color: #27272a; }
        .dark .cb-locale, .dark .cb-mic { color: #71717a; }
        .dark .cb-mic:hover { color: #a1a1aa; }
        .dark .cb-mic-live { color: #f87171; }
        .dark .cb-listening { color: #a1a1aa; }
        .dark .cb-detail { color: #a1a1aa; }
        .dark .cb-question, .dark .cb-flag { color: #fbbf24; }
        .dark .cb-select { border-color: #3f3f46; }
        .dark .cb-select option { background: #18181b; }
        .dark .cb-cancel { border-color: #27272a; color: #a1a1aa; }
        .dark .cb-footer kbd { background: #27272a; color: #a1a1aa; }
    </style>
</div>
