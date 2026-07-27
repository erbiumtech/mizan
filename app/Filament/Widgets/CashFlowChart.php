<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\JournalEntryLine;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Daily cash movement from posted journal entry lines on the cash accounts
 * (1100 Cash/Bank, 1150 Petty Cash). Debits are money in, credits are money
 * out — mirrors the two Nova DailyCashFlow trend cards.
 */
class CashFlowChart extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public ?string $filter = '14';

    public function getHeading(): ?string
    {
        return 'Daily Cash Flow';
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('AccountView');
    }

    protected function getFilters(): ?array
    {
        return [
            '14' => '14 Days',
            '30' => '30 Days',
            '60' => '60 Days',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?: 14);
        $from = Carbon::today()->subDays($days - 1);
        $accountIds = Account::whereIn('code', ['1100', '1150'])->pluck('id');

        $in = $this->sumsByDay('debit_amount', $accountIds, $from);
        $out = $this->sumsByDay('credit_amount', $accountIds, $from);

        $labels = [];
        $inData = [];
        $outData = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo(Carbon::today()); $date->addDay()) {
            $labels[] = $date->format('M j');
            $key = $date->toDateString();
            $inData[] = round((float) ($in[$key] ?? 0), 2);
            $outData[] = round((float) ($out[$key] ?? 0), 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Cash In',
                    'data' => $inData,
                    'borderColor' => '#3E894A',
                    'backgroundColor' => 'rgba(62, 137, 74, 0.1)',
                ],
                [
                    'label' => 'Cash Out',
                    'data' => $outData,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function sumsByDay(string $column, $accountIds, Carbon $from)
    {
        return JournalEntryLine::whereIn('account_id', $accountIds)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->selectRaw("DATE(journal_entries.entry_date) as day, SUM({$column}) as total")
            ->groupBy('day')
            ->pluck('total', 'day');
    }
}
