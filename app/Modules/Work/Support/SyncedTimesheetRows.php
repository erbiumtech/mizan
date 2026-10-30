<?php

namespace App\Modules\Work\Support;

use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Work\Services\WorkTime;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * A timesheet row that came from somewhere else is edited there — docs/project-management-plan.md §9.
 *
 * "The ledger does not let two tools disagree about one hour by letting both write it." A row
 * synced from Clockify or written by a smart commit is read-only on the timesheet; the next sync
 * would overwrite a hand edit anyway, and a correction that survives until Tuesday night is worse
 * than one refused on Monday.
 *
 * Registered on Timesheets' model from **this** module's provider, so Timesheets never learns what
 * a source is. A `ValidationException` rather than a `RuntimeException`, because the timesheet form
 * shows the former against the field and the API answers 422; either is the right answer, and a
 * 500 is not.
 *
 * The sync itself writes these rows through `whileSyncing()`, which is the one door: a flag for the
 * duration of the closure rather than an attribute on the model, because an attribute would be
 * saved as a column.
 */
class SyncedTimesheetRows
{
    private static bool $syncing = false;

    /** What the timesheet itself may stamp on a synced row: who approved it, when, and when it was billed. */
    public const LEDGER_COLUMNS = ['approved_by', 'approved_at', 'locked_at', 'updated_at'];

    public static function register(): void
    {
        TimesheetEntry::updating(function (TimesheetEntry $entry): void {
            if (self::$syncing || WorkTime::isEditable($entry)) {
                return;
            }

            // Approving and billing are the ledger's own stamps, not edits to the hour: a synced
            // row still has to be approved to be invoiced. Only the imported fields are refused.
            if (array_diff(array_keys($entry->getDirty()), self::LEDGER_COLUMNS) === []) {
                return;
            }

            self::refuse($entry);
        });

        TimesheetEntry::deleting(function (TimesheetEntry $entry): void {
            if (self::$syncing || WorkTime::isEditable($entry)) {
                return;
            }

            self::refuse($entry);
        });
    }

    /**
     * Run a sync, which may rewrite and remove its own rows.
     *
     * @template T
     *
     * @param  Closure(): T  $sync
     * @return T
     */
    public static function whileSyncing(Closure $sync): mixed
    {
        $was = self::$syncing;
        self::$syncing = true;

        try {
            return $sync();
        } finally {
            self::$syncing = $was;
        }
    }

    private static function refuse(TimesheetEntry $entry): never
    {
        $source = WorkTime::SOURCES[(string) $entry->getAttribute('source')] ?? (string) $entry->getAttribute('source');

        throw ValidationException::withMessages([
            'minutes' => 'This row came from '.$source.' and is edited there. The next sync would overwrite a change made here.',
        ]);
    }
}
