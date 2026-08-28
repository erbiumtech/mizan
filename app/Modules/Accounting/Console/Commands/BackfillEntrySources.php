<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Models\JournalEntry;
use App\Support\JournalEntryOwners;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Attribute the entries posted before anything filled the column in — `docs/erpnext-gap-plan.md` Phase 1.
 *
 * Every posting path now records what produced it, which fixes the ledger from today forward and does
 * nothing for the history already in it. The gap plan estimated that "most of the history is recoverable"
 * from `invoices.journal_entry_id`, and reading `JournalEntryOwners` shows it is more than that: **five**
 * document types carry a `journal_entry_id` — invoices, payments, petty cash vouchers, stock movements and
 * fixed assets — and each module registers its own. So this walks the registry rather than naming a table,
 * which means a module that is not installed contributes nothing to the backfill for the same reason it
 * contributes nothing to the report.
 *
 * **Never guesses.** The gap plan's own instruction: "Where it is not inferable, leave it null — a guessed
 * source is worse than none." Nothing here reads a memo or matches on an amount. An entry is attributed
 * only where a document points at it by id, which is a fact rather than an inference, and entries that
 * already carry a source are left exactly as they are.
 *
 * `--dry-run` because the first question anybody sensible asks of a backfill is how much it would change.
 */
class BackfillEntrySources extends Command
{
    use TenantAware;

    protected $signature = 'accounting:backfill-entry-sources {--dry-run : Report what would change and write nothing} {--tenant=*}';

    protected $description = 'Attribute historical journal entries to the documents that produced them';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $before = $this->sourceless();
        $attributed = 0;

        foreach (JournalEntryOwners::all() as $label => $model) {
            $attributed += $this->attributeFrom($label, $model, $dryRun);
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d of %d unattributed entries.',
            $dryRun ? 'Would attribute' : 'Attributed',
            $attributed,
            $before,
        ));

        // What is left is the honest remainder, and it is worth printing rather than hiding: a manual
        // journal entry has no document behind it and never will, so a company with many of them has a
        // large Unassigned row in every dimension report and should know that before it surprises them.
        $remaining = $dryRun ? $before - $attributed : $this->sourceless();

        if ($remaining > 0) {
            $this->line("{$remaining} remain unattributed — manual entries, and anything whose document was deleted.");
        }

        return self::SUCCESS;
    }

    /**
     * Attribute every entry one owner type points at.
     *
     * The document is the authority: it holds `journal_entry_id`, so the pair is recorded rather than
     * inferred. Chunked because a company's history is the one table that is large by definition.
     *
     * @param  class-string<Model>  $model
     */
    private function attributeFrom(string $label, string $model, bool $dryRun): int
    {
        $attributed = 0;

        $model::query()
            ->whereNotNull('journal_entry_id')
            ->select(['id', 'journal_entry_id'])
            ->chunkById(500, function ($documents) use ($model, $dryRun, &$attributed): void {
                $entries = JournalEntry::query()
                    ->whereIn('id', $documents->pluck('journal_entry_id')->all())
                    ->whereNull('source_type')
                    ->pluck('id')
                    ->all();

                if ($entries === []) {
                    return;
                }

                $attributed += count($entries);

                if ($dryRun) {
                    return;
                }

                foreach ($documents as $document) {
                    if (! in_array($document->journal_entry_id, $entries, true)) {
                        continue;
                    }

                    // Through the model so the alias mutator runs: `source_type` holds a stable alias and
                    // never a live class name, or the row stops resolving the day the class moves.
                    JournalEntry::query()->whereKey($document->journal_entry_id)->first()?->forceFill([
                        'source_type' => $model,
                        'source_id' => $document->id,
                    ])->save();
                }
            });

        if ($attributed > 0) {
            $this->line(sprintf('  %-28s %d', $label, $attributed));
        }

        return $attributed;
    }

    private function sourceless(): int
    {
        return JournalEntry::query()->whereNull('source_type')->count();
    }
}
