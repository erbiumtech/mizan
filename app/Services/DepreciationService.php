<?php

namespace App\Services;

use App\Support\ModuleMap;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

class DepreciationService
{
    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

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
            ['account_id' => $this->accountId('1500'), 'credit_amount' => $amount, 'description' => $asset->asset_code],
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
            $lines[] = ['account_id' => $this->accountId('1500'), 'debit_amount' => $accumulated, 'description' => "Disposal {$asset->asset_code}"];
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
