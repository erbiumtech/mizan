<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Services\PayrollPostingService;
use App\Modules\Payroll\Services\TaxCalculatorService;
use App\Support\Impersonation;
use App\Notifications\PayslipRejected;
use App\Modules\Payroll\Services\PayslipService;
use App\Traits\Auditable;
use App\Traits\HasComments;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

class Payslip extends Model
{
    use Auditable, HasComments;

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    protected $fillable = [
        'employee_id', 'month', 'fiscal_year_id', 'total_working_days', 'paid_days', 'lop_days',
        'leaves_taken', 'basic_wage', 'medical_allowance', 'device_allowance',
        'petrol_allowance', 'extra_work_hours', 'bonus', 'withholding_tax',
        'advances', 'meal_deduction', 'esi_health_insurance', 'annual_income_tax', 'total_net_income', 'total_earnings',
        'total_deductions', 'net_salary',
        // pdf_path is deliberately absent. The payslip PDF is rendered on request
        // from the payslip as it stands, never stored — see
        // PayslipService::renderPdf(). A path here would be a promise that the file
        // it names still matches the figures, and it did not.
        'employee_review', 'employee_reviewed_at', 'employee_rejection_reason', 'expense_reimbursement',
        'employee_review_recorded_by', 'employee_review_recorded_by_name',
    ];

    protected $casts = [
        'employee_reviewed_at' => 'datetime',
    ];

    /**
     * Was this acknowledgement entered by somebody other than the employee?
     */
    public function reviewWasRecordedOnBehalf(): bool
    {
        return $this->employee_review_recorded_by !== null;
    }

    /**
     * The note the payslip carries when it was: "Accepted on behalf of the
     * employee by …". Null when the employee acknowledged it themselves.
     */
    public function reviewOnBehalfNote(): ?string
    {
        if (! $this->reviewWasRecordedOnBehalf()) {
            return null;
        }

        $who = $this->employee_review_recorded_by_name ?: 'an administrator';
        $verb = $this->employee_review === self::REVIEW_REJECTED ? 'Rejected' : 'Accepted';
        $when = $this->employee_reviewed_at?->format('d M Y');

        return "{$verb} on behalf of the employee by {$who}".($when ? " on {$when}" : '').'.';
    }

    public function isPendingReview(): bool
    {
        return ($this->employee_review ?? self::REVIEW_PENDING) === self::REVIEW_PENDING;
    }

    /**
     * Employee acknowledgement of the payslip. Rejection is advisory:
     * it records the objection for the accounts team but blocks nothing.
     */
    public function recordEmployeeReview(string $status, ?string $reason = null): self
    {
        if (! in_array($status, [self::REVIEW_ACCEPTED, self::REVIEW_REJECTED])) {
            throw new \InvalidArgumentException("Invalid review status {$status}.");
        }

        if (! $this->isPendingReview()) {
            throw new \InvalidArgumentException("Payslip has already been reviewed ({$this->employee_review}).");
        }

        // Who is really at the keyboard. An administrator signed in as the employee
        // (App\Support\Impersonation) may legitimately enter this for staff who
        // cannot, but accepting a payslip is a statement of consent — so the
        // payslip records that it was entered on their behalf, and by whom, rather
        // than presenting it as the employee's own acknowledgement. Left null in
        // the ordinary case, where the employee did it themselves.
        $onBehalfOf = app(Impersonation::class)->impersonator();

        $this->update([
            'employee_review' => $status,
            'employee_reviewed_at' => now(),
            'employee_rejection_reason' => $status === self::REVIEW_REJECTED ? $reason : null,
            'employee_review_recorded_by' => $onBehalfOf?->getKey(),
            'employee_review_recorded_by_name' => $onBehalfOf?->name,
        ]);

        if ($status === self::REVIEW_REJECTED) {
            $staff = User::permission('PayslipUpdate')
                ->where('id', '!=', $this->employee?->user_id)
                ->where('status', 1)
                ->get();

            Notification::send(
                $staff,
                new PayslipRejected($this)
            );
        }

        return $this;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    protected static function booted()
    {
        $calculate = function ($payslip) {
            // Employee accept/reject only touches review columns; the
            // figures and ledger posting must stay as issued.
            if ($payslip->exists && static::isReviewOnlyChange($payslip->getDirty())) {
                return;
            }

            $service = app(PayslipService::class);

            $calculatedData = $service->calculateByParams(
                $payslip->employee_id,
                $payslip->month,
                $payslip->fiscal_year_id,
                $payslip->bonus,
                $payslip->extra_work_hours,
                $payslip->device_allowance,
                $payslip->petrol_allowance,
                $payslip->advances,
                $payslip->meal_deduction,
                $payslip->esi_health_insurance,
                $payslip->expense_reimbursement,
                $payslip->id
            );

            if ($calculatedData) {
                $payslip->basic_wage = $calculatedData['basic_wage'];
                $payslip->medical_allowance = $calculatedData['medical_allowance'];
                $payslip->device_allowance = $calculatedData['device_allowance'];
                $payslip->petrol_allowance = $calculatedData['petrol_allowance'];
                $payslip->bonus = $calculatedData['bonus'];
                $payslip->extra_work_hours = $calculatedData['extra_work_hours'];
                $payslip->expense_reimbursement = $calculatedData['expense_reimbursement'];
                $payslip->withholding_tax = $calculatedData['withholding_tax'];
                $payslip->advances = $calculatedData['advances'];
                $payslip->meal_deduction = $calculatedData['meal_deduction'];
                $payslip->esi_health_insurance = $calculatedData['esi_health_insurance'];
                $payslip->total_earnings = $calculatedData['total_earnings'];
                $payslip->total_deductions = $calculatedData['total_deductions'];
                $payslip->net_salary = $calculatedData['net_salary'];
            }
        };

        static::creating($calculate);
        static::updating($calculate);

        $syncTax = function ($payslip) {
            self::calculateAndStoreAnnualTax($payslip->employee_id, $payslip->fiscal_year_id);
        };

        static::saved(function ($payslip) use ($syncTax) {
            if (! static::isReviewOnlyChange($payslip->getChanges())) {
                $syncTax($payslip);
            }
        });
        static::deleted($syncTax);

        // Book the deduction against the employee's advances, so the balance owed
        // follows what payroll actually took. Idempotent per payslip: payroll
        // recalculates on every save, and a second row would recover the same
        // instalment twice.
        $syncAdvances = function ($payslip) {
            if (! modules()->enabled('advances')) {
                return;
            }

            app(\App\Modules\Advances\Services\AdvanceService::class)->recordRecoveryFor($payslip);
        };

        static::saved(function ($payslip) use ($syncAdvances) {
            if (! static::isReviewOnlyChange($payslip->getChanges())) {
                $syncAdvances($payslip);
            }
        });

        // Deleting the payslip gives its recovery back — the money was never
        // taken, so the balance must go up again. The cascade on payslip_id does
        // this at the database level; settling is what needs correcting.
        static::deleted(function ($payslip) {
            if (! modules()->enabled('advances')) {
                return;
            }

            \App\Modules\Advances\Models\Advance::where('employee_id', $payslip->employee_id)
                ->get()
                ->each(function ($advance) {
                    $advance->refresh();

                    if ($advance->status === \App\Modules\Advances\Models\Advance::STATUS_SETTLED
                        && $advance->remainingAmount() > 0) {
                        $advance->update(['status' => \App\Modules\Advances\Models\Advance::STATUS_ACTIVE]);
                    }
                });
        });

        // Ledger integration: (re)create the payroll journal entry on save,
        // reverse/remove it on delete.
        static::saved(function ($payslip) {
            if (! static::isReviewOnlyChange($payslip->getChanges())) {
                app(PayrollPostingService::class)->postPayslip($payslip);
            }
        });

        static::deleted(function ($payslip) {
            app(PayrollPostingService::class)->unwindForPayslip($payslip);
        });
    }

    protected static function isReviewOnlyChange(array $dirty): bool
    {
        unset($dirty['updated_at']);

        return $dirty !== []
            && array_diff_key($dirty, array_flip([
                'employee_review', 'employee_reviewed_at', 'employee_rejection_reason',
                'employee_review_recorded_by', 'employee_review_recorded_by_name',
            ])) === [];
    }

    public static function calculateAndStoreAnnualTax($employeeId, $fiscalYearId)
    {
        $payslips = self::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->get();

        if ($payslips->isEmpty()) {
            AnnualTax::where('employee_id', $employeeId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->delete();

            return;
        }

        $totalActualIncomeYearToDate = 0;
        $totalNetSalaryYearToDate = 0;
        $totalPaidTaxYearToDate = 0;
        $monthsCount = $payslips->count();

        foreach ($payslips as $p) {
            $totalActualIncomeYearToDate += (float) ($p->total_earnings ?? 0);
            $totalNetSalaryYearToDate += (float) ($p->net_salary ?? 0);
            $totalPaidTaxYearToDate += (float) ($p->withholding_tax ?? 0);
        }

        $allSettings = EmployeeSetting::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->get();

        $annualTotalEarnings = 0;
        $completedMonths = $payslips->pluck('month')->toArray();

        if ($allSettings->isNotEmpty()) {
            foreach ($allSettings as $empSetting) {
                $sMonthlyTotal = (float) $empSetting->basic_wage
                                 + (float) $empSetting->medical_allowance
                                 + (float) $empSetting->petrol_allowance
                                 + (float) $empSetting->device_allowance
                                 + (float) ($empSetting->bonus ?? 0)
                                 + (float) ($empSetting->extra_work_hours ?? 0);

                $sStart = Carbon::parse($empSetting->start_date);
                $sEnd = Carbon::parse($empSetting->end_date);

                $currentPeriodCursor = $sStart->copy();
                while ($currentPeriodCursor <= $sEnd) {
                    $mName = $currentPeriodCursor->format('F');

                    $matchedPayslip = $payslips->firstWhere('month', $mName);
                    if ($matchedPayslip) {
                        $annualTotalEarnings += (float) $matchedPayslip->total_earnings;
                    } else {
                        $annualTotalEarnings += $sMonthlyTotal;
                    }

                    $currentPeriodCursor->addMonth();
                }
            }
        } else {
            $avgMonthlyIncome = $monthsCount > 0 ? ($totalActualIncomeYearToDate / $monthsCount) : 0;
            $remainingMonths = max(0, 12 - $monthsCount);
            $annualTotalEarnings = $totalActualIncomeYearToDate + ($avgMonthlyIncome * $remainingMonths);
        }

        // Net salary projected average
        $avgMonthlyNet = $monthsCount > 0 ? ($totalNetSalaryYearToDate / $monthsCount) : 0;
        $annualProjectedNetIncome = $avgMonthlyNet * 12;

        $medicalExemption = $annualTotalEarnings * 0.10;

        // Annual Taxable Income = Total Earnings - 10% Exemption Cut
        $annualTaxableIncome = max(0, $annualTotalEarnings - $medicalExemption);

        $annualTotalTax = app(TaxCalculatorService::class)
            ->annualTax((float) $annualTaxableIncome, $fiscalYearId);
        $leftoverTax = max(0, $annualTotalTax - $totalPaidTaxYearToDate);

        AnnualTax::updateOrCreate(
            [
                'employee_id' => $employeeId,
                'fiscal_year_id' => $fiscalYearId,
            ],
            [
                'total_annual_income' => round($annualTotalEarnings, 2),
                'annual_income_tax' => round($annualTaxableIncome, 2),
                'total_net_income' => round($annualProjectedNetIncome, 2),
                'total_annual_tax' => round($annualTotalTax, 2),
                'paid_tax' => round($totalPaidTaxYearToDate, 2),
                'leftover_tax' => round($leftoverTax, 2),
            ]
        );
    }

    public static function syncAnnualTax($employeeId, $fiscalYearId)
    {
        self::calculateAndStoreAnnualTax($employeeId, $fiscalYearId);
    }
}
