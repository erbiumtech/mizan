<?php

namespace App\Filament\Livewire;

use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Services\CommandBooker;
use App\Modules\Accounting\Services\CommandInterpreter;
use App\Modules\Accounting\Support\CommandInterpretation;
use App\Support\Ai\StructuredModel;
use Filament\Notifications\Notification;
use Livewire\Component;
use Throwable;

/**
 * The ⌘J command bar — `docs/ai-command-bot-plan.md` §6, §8.
 *
 * Beside the ⌘K palette rather than inside it, and §8 gives the reason: the palette *navigates* — every
 * provider returns an item with a URL — while this proposes an action and waits. That second beat is a
 * thing the palette has no concept of, so it gets its own component and shares the palette's render hook.
 *
 * **Nothing here books anything.** It calls {@see CommandInterpreter}, renders what came back, and calls
 * {@see CommandBooker} only when somebody presses Confirm. The gate is the whole point (§6): `bookRow()`
 * approves and posts on the spot, so there is no draft to reconsider afterwards.
 */
class CommandBar extends Component
{
    public string $utterance = '';

    /**
     * What the recogniser heard, before the person edited it — §5.
     *
     * Kept apart from `$utterance` rather than merged into it, because the two failure modes have to stay
     * distinguishable: a transcript that differs from what was finally submitted is a *speech* error, and
     * one that matches while the interpretation is wrong is a *parse* error. One field holding whichever
     * happened would make them the same event after the fact, and §5 requires that they not be.
     */
    public ?string $transcript = null;

    /** The dictation language last used, recorded so a locale that transcribes badly is visible as such. */
    public ?string $speechLocale = null;

    /** The audit row being confirmed. The row is the state — see CommandInterpretation::rehydrate(). */
    public ?int $utteranceId = null;

    /** @var array<string, mixed>|null Plain strings for the view; models do not survive the round trip. */
    public ?array $preview = null;

    public bool $busy = false;

    /** Off unless configured, so the hotkey opens nothing rather than something broken. */
    public function isAvailable(): bool
    {
        return app(StructuredModel::class)->isConfigured()
            && auth()->user()?->can('create', \App\Modules\Accounting\Models\JournalEntry::class) !== false;
    }

    /** Whether the mic is offered at all. Feature detection in the browser decides the rest. */
    public function voiceEnabled(): bool
    {
        return (bool) config('ai.voice.enabled');
    }

    /** @return array<string, string> */
    public function speechLocales(): array
    {
        return (array) config('ai.voice.locales', []);
    }

    /**
     * Take a transcript from the recogniser — and stop there.
     *
     * **It lands in the input for the person to read and edit; it is not interpreted.** This is the whole
     * of §5's requirement. Acting on what the machine thought it heard is what makes a mis-transcription
     * indistinguishable from a mis-parse, and on a poorly-supported locale mis-transcription is the
     * likelier of the two.
     */
    public function dictated(string $text, ?string $locale = null): void
    {
        $this->utterance = trim($text);
        $this->transcript = trim($text);
        $this->speechLocale = $locale;

        // A new dictation replaces any proposal on screen: the old preview belongs to different words.
        $this->reset('preview', 'utteranceId');
    }

    public function interpret(): void
    {
        $this->reset('preview', 'utteranceId');

        if (trim($this->utterance) === '') {
            return;
        }

        $this->busy = true;

        try {
            $interpretation = app(CommandInterpreter::class)->interpret(
                $this->utterance,
                transcript: $this->transcript,
                locale: $this->speechLocale,
            );
        } catch (Throwable $e) {
            report($e);
            $this->busy = false;
            $this->preview = ['error' => 'Something went wrong reading that. Try again, or use the entry screens.'];

            return;
        }

        $this->busy = false;
        $this->utteranceId = $interpretation->utterance->id;
        $this->preview = $this->present($interpretation);
    }

    public function confirm(): void
    {
        $interpretation = $this->current();

        if (! $interpretation) {
            $this->preview = ['error' => 'That command is no longer waiting to be confirmed.'];

            return;
        }

        try {
            $entry = app(CommandBooker::class)->book($interpretation);
        } catch (Throwable $e) {
            $this->preview = ['error' => $e->getMessage()];

            return;
        }

        Notification::make()
            ->success()
            ->title('Booked')
            // The effect, not the command — the same rule as the confirmation line itself (§2.3).
            ->body($interpretation->effectLine().' · '.$entry->entry_number)
            ->send();

        $this->reset('utterance', 'transcript', 'speechLocale', 'preview', 'utteranceId');
        $this->dispatch('command-booked');
    }

    public function cancel(): void
    {
        if ($interpretation = $this->current()) {
            app(CommandBooker::class)->cancel($interpretation, 'dismissed from the command bar');
        }

        $this->reset('utterance', 'transcript', 'speechLocale', 'preview', 'utteranceId');
    }

    /**
     * The row being confirmed, re-read and re-scoped.
     *
     * Scoped to the signed-in user as well as the tenant: the id travels in component state, and a
     * component property is something the browser can change. Without this, a swapped id would confirm
     * somebody else's pending command.
     */
    private function current(): ?CommandInterpretation
    {
        if ($this->utteranceId === null) {
            return null;
        }

        $row = CommandUtterance::query()
            ->whereKey($this->utteranceId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $row) {
            return null;
        }

        // Through the resolver that produced it: rebuilding a proposal is domain work (an employee here,
        // a register account there), and the component must not learn either shape.
        return app(CommandInterpreter::class)->resolverFor($row->resolver)?->rehydrate($row);
    }

    /** @return array<string, mixed> */
    private function present(CommandInterpretation $interpretation): array
    {
        return [
            'effect' => $interpretation->effectLine(),
            'detail' => $interpretation->detailLine(),
            'description' => $interpretation->description,
            'questions' => $interpretation->questions,
            'flags' => $interpretation->flags,
            'complete' => $interpretation->isComplete(),
            'explicit' => $interpretation->needsExplicitConfirmation(),
            'error' => null,
        ];
    }

    public function render()
    {
        return view('filament.livewire.command-bar');
    }
}
