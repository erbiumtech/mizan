<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Restating foreign-currency balances at the rate on a date.
 *
 * A EUR bank account holds euros. That is the real balance, and it does not change when
 * the rupee moves — but the base-currency figure the balance sheet reports does, because
 * it is a translation. Each posting was translated at the rate on its own day, so by the
 * end of a month the account's base balance is a sum of historical rates and means
 * nothing in particular. Revaluation replaces it with the balance translated at one
 * rate: today's.
 *
 * The difference is a gain or a loss that nobody has realised — no money moved and none
 * will until the balance is settled — so it is reported apart from the realised kind.
 *
 * The adjustment is computed as *cumulative*: the gap between what the account's base
 * balance is and what the foreign balance is worth on the date, including every earlier
 * adjustment. Two consequences worth stating, both good:
 *
 *  - running it twice on the same date posts nothing the second time, because the gap is
 *    then zero. No reversal entry and no bookkeeping about what was already done.
 *  - a foreign transaction backdated into an already-revalued month is picked up by the
 *    next revaluation instead of being lost.
 *
 * The adjusting lines carry no foreign amount, deliberately: they move the translation,
 * not the euros. If they did, the next revaluation would count them as more currency.
 */
class CurrencyRevaluationService
{
    /**
     * Where the difference goes.
     *
     * One account per kind rather than a gain account and a loss account, so that a gain
     * of 100 that becomes a loss of 40 reads as a net 40 loss instead of as a gain of
     * 100 beside a loss of 140. Both are income accounts: a debit balance shows as a
     * negative income line, which is what a loss on translation is.
     */
    public const UNREALISED_ACCOUNT_CODE = '4400';

    public const REALISED_ACCOUNT_CODE = '4450';

    public function __construct(private JournalEntryService $entries) {}

    /**
     * The accounts that hold a currency other than the base one.
     *
     * @return Collection<int, Account>
     */
    public function accounts(): Collection
    {
        return Account::query()
            ->whereNotNull('currency_code')
            ->where('currency_code', '!=', Currency::baseCode())
            ->orderBy('code')
            ->get();
    }

    /**
     * What a revaluation on this date would do, account by account.
     *
     * Every foreign account is listed, including the ones needing no adjustment: "this
     * account is already at today's rate" is worth seeing, and an account missing from a
     * list is indistinguishable from an account nobody looked at.
     *
     * @return array{
     *     as_of: string,
     *     rows: array<int, array<string, mixed>>,
     *     gain: float,
     *     loss: float,
     *     net: float,
     *     has_adjustment: bool,
     *     problems: array<int, string>,
     * }
     */
    public function preview(?string $asOf = null): array
    {
        $asOf = $this->date($asOf);
        $rows = [];
        $problems = [];
        $gain = 0.0;
        $loss = 0.0;

        foreach ($this->accounts() as $account) {
            $foreign = $this->foreignBalance($account, $asOf);
            $base = $this->baseBalance($account, $asOf);
            $rate = ExchangeRate::for($account->currency_code, $asOf);

            if ($rate === null) {
                // Named rather than skipped quietly: an account left out of a
                // revaluation because nobody recorded a rate is exactly the balance
                // that then goes unnoticed.
                $problems[] = "{$account->code} {$account->name} is in {$account->currency_code}, "
                    ."which has no rate on or before {$asOf}.";

                continue;
            }

            $translated = round($foreign * $rate, 2);
            $adjustment = round($translated - $base, 2);

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'currency_code' => $account->currency_code,
                'foreign_balance' => round($foreign, 2),
                'rate' => round($rate, 8),
                'base_balance' => $base,
                'translated' => $translated,
                'adjustment' => $adjustment,
            ];

            $adjustment > 0 ? $gain += $adjustment : $loss += $adjustment;
        }

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'gain' => round($gain, 2),
            'loss' => round($loss, 2),
            'net' => round($gain + $loss, 2),
            'has_adjustment' => (bool) array_filter(
                $rows,
                fn (array $row): bool => abs($row['adjustment']) >= 0.005,
            ),
            'problems' => $problems,
        ];
    }

    /**
     * Post the revaluation as one adjusting entry, or nothing if nothing has moved.
     *
     * One entry, not one per account, because it is a single act on a single date and
     * reads that way in the register.
     */
    public function revalue(?string $asOf = null, ?int $userId = null): ?JournalEntry
    {
        $asOf = $this->date($asOf);

        $this->refuseIfLaterRevaluationExists($asOf);

        $preview = $this->preview($asOf);
        $lines = [];

        foreach ($preview['rows'] as $row) {
            if (abs($row['adjustment']) < 0.005) {
                continue;
            }

            $lines[] = [
                'account_id' => $row['account_id'],
                'debit_amount' => $row['adjustment'] > 0 ? $row['adjustment'] : 0,
                'credit_amount' => $row['adjustment'] < 0 ? -$row['adjustment'] : 0,
                'description' => "{$row['currency_code']} ".number_format($row['foreign_balance'], 2)
                    .' at '.number_format($row['rate'], 4),
            ];
        }

        if ($lines === []) {
            return null;
        }

        $net = round(array_sum(array_map(
            fn (array $line): float => (float) ($line['debit_amount'] ?? 0) - (float) ($line['credit_amount'] ?? 0),
            $lines,
        )), 2);

        $unrealised = $this->unrealisedAccount();

        $lines[] = [
            'account_id' => $unrealised->id,
            'debit_amount' => $net < 0 ? -$net : 0,
            'credit_amount' => $net > 0 ? $net : 0,
            'description' => $net > 0 ? 'Unrealised gain on translation' : 'Unrealised loss on translation',
        ];

        $entry = $this->entries->create([
            'entry_date' => $asOf,
            'entry_type' => 'adjusting',
            'memo' => 'Currency revaluation as at '.$asOf,
            'created_by' => $userId ?? auth()->id(),
        ], $lines);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $this->entries->post($entry);
    }

    /**
     * Revaluing an earlier date than the last one is refused.
     *
     * Each adjustment is the gap left by the ones before it, so they only compose in
     * date order. Posting an earlier one behind a later one would leave the later date
     * translated at neither rate, and nothing would say so.
     */
    private function refuseIfLaterRevaluationExists(string $asOf): void
    {
        $later = JournalEntry::query()
            ->where('entry_type', 'adjusting')
            ->where('memo', 'like', 'Currency revaluation as at %')
            ->whereDate('entry_date', '>', $asOf)
            ->orderByDesc('entry_date')
            ->first();

        if ($later) {
            throw new InvalidArgumentException(
                'Balances have already been revalued as at '.$later->entry_date->toDateString()
                .", which is after {$asOf}. Revalue that date again instead — each adjustment "
                .'assumes the ones before it, so they only compose forwards.'
            );
        }
    }

    /** The account's own currency balance: what it actually holds. */
    public function foreignBalance(Account $account, ?string $asOf = null): float
    {
        $lines = $this->postedLines($account, $this->date($asOf));

        return round(
            (float) (clone $lines)->sum('foreign_debit_amount')
            - (float) $lines->sum('foreign_credit_amount'),
            2,
        );
    }

    /** What the books currently say it is worth, being a sum of historical rates. */
    public function baseBalance(Account $account, ?string $asOf = null): float
    {
        $lines = $this->postedLines($account, $this->date($asOf));

        return round((float) (clone $lines)->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }

    private function postedLines(Account $account, string $asOf): Builder
    {
        return JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('is_posted', true)
                ->whereDate('entry_date', '<=', $asOf));
    }

    public function unrealisedAccount(): Account
    {
        return $this->account(self::UNREALISED_ACCOUNT_CODE, 'unrealised');
    }

    public function realisedAccount(): Account
    {
        return $this->account(self::REALISED_ACCOUNT_CODE, 'realised');
    }

    private function account(string $code, string $kind): Account
    {
        return Account::where('code', $code)->first() ?? throw new InvalidArgumentException(
            "There is no account {$code} for {$kind} exchange differences. "
            .'Seed the chart of accounts, or add it, before revaluing.'
        );
    }

    private function date(?string $asOf): string
    {
        return Carbon::parse($asOf ?? now())->toDateString();
    }
}
