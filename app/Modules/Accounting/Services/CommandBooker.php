<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\CommandInterpretation;
use InvalidArgumentException;

/**
 * The one class in this feature that moves money — `docs/ai-command-bot-plan.md` §1, §6.
 *
 * It books nothing itself. `RegisterEntryService::bookRow()` does the accounting, and that is the whole
 * architectural claim: the direction resolved from words becomes its `direction` parameter, and a service
 * that has been booking balanced, posted, audited two-line entries since long before this feature decides
 * which leg is which.
 *
 * The sign rules it applies, for the reader who wants them here rather than one file away — a register
 * account is always an asset, so:
 *
 *   money in  → debit the register account (asset increases), credit the counter-account
 *   money out → credit the register account (asset decreases), debit the counter-account
 *
 * The counter-leg needs no classification. Whatever the counter-account's type, "total debits equal total
 * credits" fixes its side, and the standard rules make that side mean the right thing: an expense debited
 * goes up, a revenue credited goes up, a liability credited goes up, a liability debited goes down.
 */
class CommandBooker
{
    public function __construct(private readonly CommandInterpreter $interpreter) {}

    /**
     * Book a confirmed interpretation.
     *
     * Refuses an incomplete one rather than filling in a blank. There is no partial booking: a command
     * with an unresolved category is a question, and answering it by guessing is the failure §3.2 exists
     * to prevent.
     */
    public function book(CommandInterpretation $interpretation): mixed
    {
        if (! $interpretation->isComplete()) {
            throw new InvalidArgumentException(
                'That command is not resolved yet: '.implode(' ', $interpretation->questions)
            );
        }

        $resolver = $this->interpreter->resolverFor($interpretation->resolverKey);

        if ($resolver === null) {
            // Re-checked at commit, not just at interpret: a licence can lapse or a permission be revoked
            // between the proposal and the confirmation, and those are exactly the seconds in which a
            // stale proposal must stop working rather than quietly still work.
            throw new InvalidArgumentException(
                'That kind of command is no longer available to you.'
            );
        }

        $result = $resolver->commit($interpretation);

        $interpretation->utterance->update([
            'outcome' => CommandUtterance::OUTCOME_BOOKED,
            // Only a journal entry gets the column; a claim or a cost entry is identified by its resolver
            // and its `resolved` slots. A morph column was the alternative and buys nothing here — nothing
            // reads back from the utterance to the thing it made except a person asking what happened.
            'journal_entry_id' => $result instanceof JournalEntry ? $result->id : null,
            'outcome_reason' => null,
        ]);

        return $result;
    }

    /** Shown and dismissed. Recorded, because an abandoned command is the interesting kind (§9). */
    public function cancel(CommandInterpretation $interpretation, ?string $reason = null): void
    {
        $interpretation->utterance->update([
            'outcome' => CommandUtterance::OUTCOME_CANCELLED,
            'outcome_reason' => $reason,
        ]);
    }
}
