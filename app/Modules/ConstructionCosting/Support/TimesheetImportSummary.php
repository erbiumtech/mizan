<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * What an import of timesheet entries did, and what it would not touch — `docs/construction-management-plan.md` §7.1.
 *
 * **Skipped rows are named, one sentence each.** The precedent is Attendance's importer, which "reports the leave clash
 * and keeps the other rows": an import that refuses a whole file over one bad row is a morning's work lost, and one
 * that silently drops rows is worse — the hours simply never reach the job and no screen says so.
 *
 * `alreadyImported` is counted separately from `skipped` because it is not a problem. Running the import twice for the
 * same fortnight is the ordinary way somebody makes sure they got everything, and "42 were already in" is a reassuring
 * sentence where "42 skipped" would send them looking for a fault.
 */
final readonly class TimesheetImportSummary
{
    /**
     * @param  int  $imported  labour records created, all as drafts
     * @param  int  $alreadyImported  entries a previous run had already brought in
     * @param  array<int, string>  $skipped  one sentence per entry that could not be imported, saying why
     * @param  bool  $previewOnly  true when nothing was written
     */
    public function __construct(
        public int $imported = 0,
        public int $alreadyImported = 0,
        public array $skipped = [],
        public bool $previewOnly = false,
    ) {}

    public function considered(): int
    {
        return $this->imported + $this->alreadyImported + count($this->skipped);
    }

    /** A sentence for a notification, which is where this normally ends up. */
    public function summary(): string
    {
        if ($this->considered() === 0) {
            return 'No approved timesheet entries in that period.';
        }

        $parts = [
            ($this->previewOnly ? 'Would import ' : 'Imported ').$this->imported.' as draft site '
                .($this->imported === 1 ? 'sheet' : 'sheets'),
        ];

        if ($this->alreadyImported > 0) {
            $parts[] = $this->alreadyImported.' already imported';
        }

        if ($this->skipped !== []) {
            $parts[] = count($this->skipped).' could not be imported';
        }

        return implode('. ', $parts).'.';
    }
}
