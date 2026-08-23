<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Support\ModuleMap;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

class DepreciationService
{
    /**
     * Accumulated depreciation, by code — the contra-asset every asset's depreciation is credited to.
     *
     * A constant because three things name it now rather than one: the posting below, `bookedFor()`, and the
     * register report that reads both. `ChartOfAccountsSeeder` is where it comes from.
     */
    public const ACCUMULATED_CODE = '1500';

    public function __construct(private JournalEntryService $journalEntryService) {}

    /**
     * Book one month of depreciation for every eligible asset.
     * Returns the created journal entries.
     */
    public function runForMonth(Carbon $month, ?int $fiscalYearId = null): array
    {
        $entries = [];

        foreach (FixedAsset::where('status', FixedAsset::STATUS_ACTIVE)->get() as $asset) {
            $entry = $this->depreciateAsset($asset, $month, $fiscalYearId);

            if ($entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Book one month of depreciation for a single asset. Returns null when
     * nothing is due (not yet purchased, already booked, fully depreciated).
     *
     * Depreciation entries are system-calculated, so they are auto-approved
     * and posted immediately — keeping the asset register's cached
     * accumulated_depreciation consistent with the ledger.
     */
    public function depreciateAsset(FixedAsset $asset, Carbon $month, ?int $fiscalYearId = null): ?JournalEntry
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        if (! $asset->isDepreciable()) {
            return null;
        }

        if ($asset->purchase_date->gt($monthEnd)) {
            return null; // not purchased yet in that month
        }

        $alreadyBooked = $asset->journalEntries()
            ->where('entry_type', '!=', 'reversing')
            ->whereDate('entry_date', '>=', $monthStart->toDateString())
            ->whereDate('entry_date', '<=', $monthEnd->toDateString())
            ->where('memo', 'like', 'Depreciation%')
            ->exists();

        if ($alreadyBooked) {
            return null;
        }

        $amount = min($asset->monthlyDepreciation(), $asset->remainingDepreciable());

        if ($amount <= 0) {
            return null;
        }

        $entry = $this->journalEntryService->create([
            'entry_date' => $monthEnd->toDateString(),
            'entry_type' => 'adjusting',
            'memo' => "Depreciation {$monthEnd->format('M Y')} — {$asset->asset_code} {$asset->name}",
            'fiscal_year_id' => $fiscalYearId,
            'source_type' => ModuleMap::alias(FixedAsset::class),
            'source_id' => $asset->id,
        ], [
            ['account_id' => $this->accountId('5990'), 'debit_amount' => $amount, 'description' => $asset->asset_code],
            ['account_id' => $this->accountId(self::ACCUMULATED_CODE), 'credit_amount' => $amount, 'description' => $asset->asset_code],
        ]);

        $this->approveAndPostSystemEntry($entry);

        $asset->accumulated_depreciation = (float) $asset->accumulated_depreciation + $amount;

        if ($asset->remainingDepreciable() <= 0) {
            $asset->status = FixedAsset::STATUS_FULLY_DEPRECIATED;
        }

        $asset->save();

        return $entry;
    }

    /**
     * What this asset *would* be charged over the months ahead, without booking any of it.
     *
     * `docs/reports-expansion-plan.md` Phase 2.5 asks for "the next twelve months' charge from
     * `DepreciationService`'s own method". There was no such method: `runForMonth()` and `depreciateAsset()`
     * both post, approve and mutate `accumulated_depreciation`, and `dispose()` writes the asset off. So the
     * only way to obtain a forecast was to run the thing being forecast — twelve months of real journal
     * entries to answer a question about the future. This is that method.
     *
     * **It projects through the model's own arithmetic, on a copy.** `monthlyDepreciation()` reads
     * accumulated depreciation through `book_value`, so walking a replica forward month by month gives the
     * declining-balance decay for free and — more to the point — guarantees the forecast matches what
     * `depreciateAsset()` will actually book. A second implementation of the `2 / life` rate would be free to
     * drift from the entries it predicts, and nothing would notice until a reader compared the two by hand.
     *
     * `$accumulated` overrides the row's cached figure, which is what lets the forecast honour an "as at"
     * date: a register drawn to a past date projects from the depreciation booked by *then*, not from
     * today's total. Left null it starts where the asset actually stands.
     *
     * Months are returned only where something is charged. A month before the purchase date is skipped
     * rather than returned as nought — `depreciateAsset()` books nothing for it either — and the walk stops
     * for good once the depreciable base is exhausted.
     *
     * @return array<int, array{month: string, amount: float}>
     */
    public function schedule(FixedAsset $asset, Carbon $from, int $months = 12, ?float $accumulated = null): array
    {
        if ($months < 1 || $asset->status === FixedAsset::STATUS_DISPOSED) {
            return [];
        }

        // A replica, never saved. `useful_life_months`, `purchase_cost` and `salvage_value` come along, so
        // the copy answers `monthlyDepreciation()` and `remainingDepreciable()` exactly as the row would at
        // that point in its life.
        $projected = $asset->replicate();
        $projected->accumulated_depreciation = $accumulated ?? (float) $asset->accumulated_depreciation;

        $schedule = [];
        $month = $from->copy()->startOfMonth();

        for ($i = 0; $i < $months; $i++, $month->addMonthNoOverflow()) {
            $monthEnd = $month->copy()->endOfMonth();

            if ($asset->purchase_date->gt($monthEnd)) {
                continue; // not owned yet in that month
            }

            // The same cap `depreciateAsset()` applies. Declining balance approaches salvage without ever
            // reaching it, so this is what ends the schedule rather than the rate falling to nought.
            $amount = min($projected->monthlyDepreciation(), $projected->remainingDepreciable());

            if ($amount <= 0) {
                break; // fully depreciated; no later month brings it back
            }

            $schedule[] = ['month' => $monthEnd->toDateString(), 'amount' => round($amount, 2)];

            $projected->accumulated_depreciation = (float) $projected->accumulated_depreciation + $amount;
        }

        return $schedule;
    }

    /**
     * What each asset has actually had booked against it, from the ledger rather than from its own column.
     *
     * **This is the definition of accumulated depreciation, and it lives here so there is only one of it.**
     * `fixed_assets.accumulated_depreciation` is a cache this service maintains as it posts; the entries
     * against account 1500 are the fact. Two readers need the fact — the register report, which must state it
     * as at a date the cache cannot answer for, and `rebuildCache()`, which repairs the cache from it — and a
     * second implementation of one figure is the drift this codebase keeps finding.
     *
     * Credits less debits, so a disposal (which debits 1500 to clear what accumulated) and a reversal both
     * net off on their own. Not a `memo like 'Depreciation%'` match: that is how `depreciateAsset()` decides
     * whether a month is already booked, which is fine for that question and would silently drop an asset's
     * whole history here the day somebody reworded a memo.
     *
     * Returns **null** when there is no accumulated-depreciation account at all, which is a different answer
     * from "no entries found" and the caller has to be able to tell them apart. `accountId()` throws for the
     * posting path, where a missing account really is unrecoverable; a reader has something to say instead.
     *
     * @param  array<int, int>  $assetIds
     * @param  string|null  $asOf  ledger date to read up to; null reads everything posted
     * @return array<int, array{accumulated: float, last_booked: string|null}>|null
     */
    public function bookedFor(array $assetIds, ?string $asOf = null): ?array
    {
        $account = Account::where('code', self::ACCUMULATED_CODE)->first();

        if (! $account) {
            return null;
        }

        if ($assetIds === []) {
            return [];
        }

        return JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.account_id', $account->getKey())
            ->where('journal_entries.is_posted', true)
            ->when($asOf !== null, fn ($query) => $query->whereDate('journal_entries.entry_date', '<=', $asOf))
            ->where('journal_entries.source_type', ModuleMap::alias(FixedAsset::class))
            ->whereIn('journal_entries.source_id', $assetIds)
            ->groupBy('journal_entries.source_id')
            ->selectRaw(implode(', ', [
                'journal_entries.source_id as asset_id',
                'SUM(journal_entry_lines.credit_amount) - SUM(journal_entry_lines.debit_amount) as accumulated',
                'MAX(journal_entries.entry_date) as last_booked',
            ]))
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->asset_id => [
                    'accumulated' => round((float) $row->accumulated, 2),
                    // Normalised to a date, because `MAX()` over a datetime column answers
                    // "2026-08-31 00:00:00" and the month is the only part that means anything here.
                    // `Carbon::parse()` accepts either, so a caller would not have noticed — which is
                    // exactly why the contract should not have been left ambiguous.
                    'last_booked' => $row->last_booked === null
                        ? null
                        : Carbon::parse($row->last_booked)->toDateString(),
                ],
            ])
            ->all();
    }

    /**
     * Put the cached figure back in step with the entries, and the status back in step with the figure.
     *
     * The Fixed Asset Register report can *see* this drift — it reads the entries and the column side by
     * side, and it is the only thing in the application that does — and until this method existed it could
     * only say so. A figure somebody is told is wrong and cannot fix is half a feature, and this is not a
     * cosmetic half: `book_value` comes off the cached column, so every declining-balance charge from that
     * point on is computed from it, as is every book value on the register screen and a disposal's loss.
     *
     * **The status is derived, not preserved.** `fully_depreciated` is a statement about the figure, so an
     * asset whose cache overstated what was booked goes back to `active` with life left in it — that is the
     * repair, not a side effect of it. A disposed asset keeps its status: disposal is an event, not a
     * position, and nothing here should bring one back onto the books.
     *
     * Returns the figure written, or null when there is no ledger to derive one from — in which case the
     * cache is left exactly as it is rather than being zeroed, because "cannot tell" is not "nothing".
     */
    public function rebuildCache(FixedAsset $asset): ?float
    {
        $booked = $this->bookedFor([$asset->getKey()]);

        if ($booked === null) {
            return null;
        }

        $accumulated = round((float) ($booked[$asset->getKey()]['accumulated'] ?? 0.0), 2);

        $asset->accumulated_depreciation = $accumulated;

        if ($asset->status !== FixedAsset::STATUS_DISPOSED) {
            $asset->status = $asset->remainingDepreciable() <= 0
                ? FixedAsset::STATUS_FULLY_DEPRECIATED
                : FixedAsset::STATUS_ACTIVE;
        }

        $asset->save();

        return $accumulated;
    }

    /**
     * Write the asset off: clear accumulated depreciation, remove the cost
     * from the asset account, book the remaining book value as a loss.
     */
    public function dispose(FixedAsset $asset, ?Carbon $date = null): JournalEntry
    {
        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            throw new InvalidArgumentException("Asset {$asset->asset_code} is already disposed.");
        }

        $date = $date ?? now();
        $accumulated = (float) $asset->accumulated_depreciation;
        $bookValue = $asset->book_value;

        $lines = [];

        if ($accumulated > 0) {
            $lines[] = ['account_id' => $this->accountId(self::ACCUMULATED_CODE), 'debit_amount' => $accumulated, 'description' => "Disposal {$asset->asset_code}"];
        }

        if ($bookValue > 0) {
            $lines[] = ['account_id' => $this->accountId('5995'), 'debit_amount' => $bookValue, 'description' => "Loss on disposal {$asset->asset_code}"];
        }

        $lines[] = ['account_id' => $asset->account_id, 'credit_amount' => (float) $asset->purchase_cost, 'description' => "Disposal {$asset->asset_code}"];

        $entry = $this->journalEntryService->create([
            'entry_date' => $date->toDateString(),
            'entry_type' => 'general',
            'memo' => "Disposal of {$asset->asset_code} {$asset->name}",
            'source_type' => ModuleMap::alias(FixedAsset::class),
            'source_id' => $asset->id,
        ], $lines);

        $this->approveAndPostSystemEntry($entry);

        $asset->update([
            'status' => FixedAsset::STATUS_DISPOSED,
            'disposed_at' => $date,
        ]);

        return $entry;
    }

    protected function approveAndPostSystemEntry(JournalEntry $entry): void
    {
        $entry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $this->journalEntryService->post($entry);
    }

    protected function accountId(string $code): int
    {
        $account = Account::where('code', $code)->first();

        if (! $account) {
            throw new RuntimeException("Account {$code} not found. Run ChartOfAccountsSeeder.");
        }

        return $account->id;
    }
}
