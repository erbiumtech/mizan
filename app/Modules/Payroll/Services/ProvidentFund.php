<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\ProvidentFundEntry;
use App\Support\Contracts\ProvidentFundBalance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** The ledger's writer and reader — docs/saas-launch-plan.md §14.1. */
class ProvidentFund implements ProvidentFundBalance
{
    public function __construct(private readonly StatutoryContributions $statutory) {}

    public function record(Employee|int $employee, string $kind, float $employeeAmount, float $employerAmount = 0.0, ?string $reference = null, string|CarbonInterface|null $on = null, ?User $by = null, ?int $payslipId = null): ProvidentFundEntry
    {
        return ProvidentFundEntry::create([
            'employee_id' => $employee instanceof Employee ? $employee->getKey() : $employee,
            'entry_date' => $on ? Carbon::parse($on)->toDateString() : now()->toDateString(),
            'kind' => $kind,
            'employee_amount' => round($employeeAmount, 2),
            'employer_amount' => round($employerAmount, 2),
            'reference' => $reference,
            'payslip_id' => $payslipId,
            'recorded_by' => $by?->getKey(),
        ]);
    }

    /**
     * The month's contributions, one entry per payslip, when a run is signed off.
     * Idempotent on the payslip: a run reopened and signed off again adds nothing.
     */
    public function recordContributions(PayrollRun $run): int
    {
        if (! $this->statutory->providentFundEnabled()) {
            return 0;
        }

        $written = 0;
        $run->loadMissing('fiscalYear');
        $on = \App\Support\PayrollMonth::lastDay($run->month, $run->fiscalYear)->toDateString();

        foreach ($run->payslips()->with('employee')->get() as $payslip) {
            if ($payslip->employee === null || ProvidentFundEntry::query()->where('payslip_id', $payslip->getKey())->where('kind', ProvidentFundEntry::KIND_CONTRIBUTION)->exists()) {
                continue;
            }

            $figures = $this->statutory->for($payslip->employee);
            $employee = (float) ($figures['provident_fund_employee'] ?? 0);
            $employer = (float) ($figures['provident_fund_employer'] ?? 0);

            if ($employee + $employer <= 0) {
                continue;
            }

            $this->record($payslip->employee, ProvidentFundEntry::KIND_CONTRIBUTION, $employee, $employer, "{$run->month} {$run->fiscalYear->name}", $on, null, $payslip->getKey());
            $written++;
        }

        return $written;
    }

    public function balanceFor(int $employeeId, ?CarbonInterface $asOf = null): float
    {
        return round(ProvidentFundEntry::query()
            ->forEmployee($employeeId)
            ->when($asOf, fn ($q) => $q->whereDate('entry_date', '<=', Carbon::instance($asOf)->toDateString()))
            ->get()
            ->sum(fn (ProvidentFundEntry $e): float => $e->net()), 2);
    }

    /** @return array{employee: float, employer: float, profit: float, withdrawn: float, balance: float} */
    public function summary(int $employeeId): array
    {
        $entries = ProvidentFundEntry::query()->forEmployee($employeeId)->get();
        $contributions = $entries->whereIn('kind', [ProvidentFundEntry::KIND_OPENING, ProvidentFundEntry::KIND_CONTRIBUTION, ProvidentFundEntry::KIND_ADJUSTMENT]);

        return [
            'employee' => round((float) $contributions->sum('employee_amount'), 2),
            'employer' => round((float) $contributions->sum('employer_amount'), 2),
            'profit' => round((float) $entries->where('kind', ProvidentFundEntry::KIND_PROFIT)->sum(fn ($e) => (float) $e->employee_amount + (float) $e->employer_amount), 2),
            'withdrawn' => round((float) $entries->where('kind', ProvidentFundEntry::KIND_WITHDRAWAL)->sum(fn ($e) => abs((float) $e->employee_amount + (float) $e->employer_amount)), 2),
            'balance' => round($entries->sum(fn (ProvidentFundEntry $e): float => $e->net()), 2),
        ];
    }
}
