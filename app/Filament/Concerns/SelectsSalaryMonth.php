<?php

namespace App\Filament\Concerns;

use App\Models\FiscalYear;
use App\Models\Payslip;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Illuminate\Support\Collection;

/**
 * The fiscal year + salary month pickers shared by the Bank Payment File and
 * Salary Bank File pages.
 *
 * Both used to list only the months that already had payslips in the active
 * fiscal year, which meant a fresh year offered a single month (or none) and
 * there was no way to build a file for any other period. The month list is now
 * the full fiscal year, in fiscal order, labelled with its calendar year — and
 * the fiscal year itself is selectable, because "July" is ambiguous when more
 * than one year is marked active.
 */
trait SelectsSalaryMonth
{
    public function fiscalYears(): Collection
    {
        return FiscalYear::orderByDesc('start_date')->get();
    }

    /**
     * The year in play: the user's choice, else the one containing today, else
     * an active one, else the most recent.
     */
    public function fiscalYear(): ?FiscalYear
    {
        $selected = $this->data['fiscal_year_id'] ?? null;

        if ($selected && $year = FiscalYear::find($selected)) {
            return $year;
        }

        return $this->defaultFiscalYear();
    }

    protected function defaultFiscalYear(): ?FiscalYear
    {
        return FiscalYear::containing(now()->toDateString())
            ?? FiscalYear::where('is_active', true)->orderByDesc('start_date')->first()
            ?? FiscalYear::orderByDesc('start_date')->first();
    }

    /**
     * All twelve months of the selected fiscal year, starting at the month the
     * year starts in (July → June for a Pakistani July–June year).
     *
     * @return Collection<int, string> month names as stored on payslips
     */
    public function months(): Collection
    {
        $fiscalYear = $this->fiscalYear();

        $start = $fiscalYear?->start_date
            ? Carbon::parse($fiscalYear->start_date)->startOfMonth()
            : now()->startOfMonth();

        return collect(range(0, 11))
            ->map(fn (int $offset) => $start->copy()->addMonths($offset)->format('F'))
            ->values();
    }

    /**
     * Month options labelled with the calendar year the month falls in, and with
     * a payslip count so an empty period is obvious before a file is built
     * rather than after.
     *
     * @return array<string, string>
     */
    public function monthOptions(): array
    {
        $fiscalYear = $this->fiscalYear();

        if (! $fiscalYear) {
            return [];
        }

        $counts = Payslip::where('fiscal_year_id', $fiscalYear->id)
            ->selectRaw('month, COUNT(*) as aggregate')
            ->groupBy('month')
            ->pluck('aggregate', 'month');

        $start = Carbon::parse($fiscalYear->start_date ?? now())->startOfMonth();

        return collect(range(0, 11))
            ->mapWithKeys(function (int $offset) use ($start, $counts) {
                $date = $start->copy()->addMonths($offset);
                $month = $date->format('F');
                $count = (int) ($counts[$month] ?? 0);

                return [$month => $date->format('F Y').($count ? " — {$count} payslips" : '')];
            })
            ->all();
    }

    /** The month to start on: the current one if it's in the year, else the first. */
    protected function defaultMonth(): ?string
    {
        $months = $this->months();

        if ($months->isEmpty()) {
            return null;
        }

        return $months->contains(now()->format('F'))
            ? now()->format('F')
            : $months->first();
    }

    protected function fiscalYearSelect(): Select
    {
        return Select::make('fiscal_year_id')
            ->label('Fiscal year')
            ->options($this->fiscalYears()->pluck('name', 'id')->all())
            ->default(fn () => $this->defaultFiscalYear()?->id)
            ->selectablePlaceholder(false)
            ->native(false)
            ->live()
            // The month list belongs to the year, so reset it when the year moves.
            ->afterStateUpdated(fn () => $this->data['month'] = $this->defaultMonth());
    }

    protected function monthSelect(): Select
    {
        return Select::make('month')
            ->label('Salary month')
            ->options(fn (): array => $this->monthOptions())
            ->selectablePlaceholder(false)
            ->native(false)
            ->live();
    }
}
