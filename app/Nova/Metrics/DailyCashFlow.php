<?php

namespace App\Nova\Metrics;

use App\Models\Account;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Trend;
use Laravel\Nova\Metrics\TrendResult;

/**
 * Daily cash movement from posted journal entry lines on the cash
 * accounts (1100 Cash/Bank, 1150 Petty Cash). One instance per
 * direction: debits are money in, credits are money out.
 */
class DailyCashFlow extends Trend
{
    public function __construct(public string $direction)
    {
        parent::__construct();

        $this->name = $direction === 'in' ? 'Cash In (Daily)' : 'Cash Out (Daily)';
    }

    public function calculate(NovaRequest $request): TrendResult
    {
        $days = (int) ($request->range ?: 14);
        $column = $this->direction === 'in' ? 'debit_amount' : 'credit_amount';

        $accountIds = Account::whereIn('code', ['1100', '1150'])->pluck('id');
        $from = Carbon::today()->subDays($days - 1);

        $sums = JournalEntryLine::whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')
                ->whereDate('entry_date', '>=', $from))
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->selectRaw("DATE(journal_entries.entry_date) as day, SUM({$column}) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = [];
        for ($date = $from->copy(); $date->lessThanOrEqualTo(Carbon::today()); $date->addDay()) {
            $trend[$date->format('M j')] = round((float) ($sums[$date->toDateString()] ?? 0), 2);
        }

        return (new TrendResult)->trend($trend)
            ->result(round((float) $sums->sum(), 2))
            ->prefix('PKR ');
    }

    public function ranges(): array
    {
        return [14 => '14 Days', 30 => '30 Days', 60 => '60 Days'];
    }

    public function uriKey(): string
    {
        return 'daily-cash-'.$this->direction;
    }
}
