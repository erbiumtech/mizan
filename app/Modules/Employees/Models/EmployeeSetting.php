<?php

namespace App\Modules\Employees\Models;

use App\Models\FiscalYear;
use App\Models\TenantModel as Model;
use App\Models\User;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class EmployeeSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id', 'fiscal_year_id', 'start_date', 'end_date', 'basic_wage', 'medical_allowance', 'device_allowance',
        'petrol_allowance', 'advances', 'meal_deduction', 'esi_health_insurance',
        'bonus', 'extra_work_hours',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    protected static function booted()
    {
        $setDefaultEndDate = function ($setting) {
            if (empty($setting->end_date) && ! empty($setting->start_date)) {
                $start = Carbon::parse($setting->start_date);
                $year = $start->month >= 7 ? $start->year + 1 : $start->year;
                $setting->end_date = Carbon::create($year, 6, 30)->toDateString();
            }
        };

        static::creating(function ($setting) use ($setDefaultEndDate) {
            $setDefaultEndDate($setting);

            $previousSetting = self::where('employee_id', $setting->employee_id)
                ->where('id', '!=', $setting->id ?? 0)
                ->where('start_date', '<', $setting->start_date)
                ->orderBy('start_date', 'desc')
                ->first();

            if ($previousSetting) {
                $newStart = Carbon::parse($setting->start_date);

                $calculatedEnd = $newStart->copy()->subMonth()->endOfMonth();

                $prevStart = Carbon::parse($previousSetting->start_date);
                $maxFiscalYearEndYear = $prevStart->month >= 7 ? $prevStart->year + 1 : $prevStart->year;
                $maxFiscalYearEndDate = Carbon::create($maxFiscalYearEndYear, 6, 30);

                if ($calculatedEnd->gt($maxFiscalYearEndDate)) {
                    $previousSetting->end_date = $maxFiscalYearEndDate->toDateString();
                } else {
                    $previousSetting->end_date = $calculatedEnd->toDateString();
                }

                $previousSetting->save();
            }
        });

        static::updating(function ($setting) use ($setDefaultEndDate) {
            // An edit that has been turned into a change request must not also
            // write a derived end date.
            if ($setting->routedToApproval) {
                return false;
            }

            $setDefaultEndDate($setting);
        });

        static::saving(function (EmployeeSetting $setting) {
            $setting->routeChangesThroughApproval();
        });
    }

    /** True once this instance's edit has been parked as a change request. */
    public bool $routedToApproval = false;

    /** Set while an approved request is being written, to avoid re-routing it. */
    protected static bool $skipApprovalRouting = false;

    /**
     * Run a write that must land directly, skipping the self-service
     * interception below (used when applying an approved change request).
     */
    public static function withoutApprovalRouting(callable $callback): mixed
    {
        static::$skipApprovalRouting = true;

        try {
            return $callback();
        } finally {
            static::$skipApprovalRouting = false;
        }
    }

    /**
     * Whether $user editing this row is a self-service edit — the employee the
     * settings belong to, without a role that can approve on their own.
     *
     * Mirrors the same rule on {@see Employee}: privileged roles edit directly.
     */
    public function isSelfServiceEditFor(?User $user): bool
    {
        return $user !== null
            && $this->exists
            && $this->employee?->user_id === $user->id
            && ! $user->hasAnyRole(['Administrator', 'Manager', 'CEO']);
    }

    /**
     * An employee editing their own compensation figures raises a pending
     * EmployeeChangeRequest instead of changing the row; approvers' edits apply
     * immediately. Fields outside SETTING_FIELDS are dropped, not requested.
     */
    protected function routeChangesThroughApproval(): void
    {
        if (static::$skipApprovalRouting || ! $this->isSelfServiceEditFor(Auth::user())) {
            return;
        }

        $changes = collect($this->getDirty())->only(EmployeeChangeRequest::SETTING_FIELDS);

        if ($changes->isNotEmpty()) {
            EmployeeChangeRequest::create([
                'employee_id' => $this->employee_id,
                'target_type' => EmployeeChangeRequest::TARGET_SETTING,
                'target_id' => $this->getKey(),
                'requested_by' => Auth::id(),
                'requested_changes' => $changes->all(),
                'original_values' => $changes->keys()
                    ->mapWithKeys(fn ($key) => [$key => $this->getRawOriginal($key)])
                    ->all(),
            ]);
        }

        // Leave the row untouched until the request is approved. Also discards
        // any edit to a field an employee may not request.
        $this->routedToApproval = true;
        $this->setRawAttributes($this->getRawOriginal());
    }

    public static function getActiveSettingForDate($employeeId, $date, $fiscalYearId = null)
    {
        $fiscalYearRecord = $fiscalYearId ? FiscalYear::find($fiscalYearId) : null;

        $startYear = 2026;
        $endYear = 2027;

        if ($fiscalYearRecord && str_contains($fiscalYearRecord->name, '-')) {
            $parts = explode('-', $fiscalYearRecord->name);
            $startYear = (int) trim($parts[0]);
            $endYear = (int) trim($parts[1]);
        } elseif ($fiscalYearRecord) {
            $startYear = Carbon::parse($fiscalYearRecord->start_date)->year;
            $endYear = $startYear + 1;
        }

        $targetCarbon = Carbon::parse($date);
        $targetMonthNum = $targetCarbon->month;

        $targetActualYear = ($targetMonthNum >= 7 && $targetMonthNum <= 12) ? $startYear : $endYear;

        $targetYearMonth = sprintf('%04d-%02d', $targetActualYear, $targetMonthNum);

        return self::where('employee_id', $employeeId)
            ->when($fiscalYearId, function ($q) use ($fiscalYearId) {
                return $q->where('fiscal_year_id', $fiscalYearId);
            })
            ->get()
            ->filter(function ($item) use ($targetYearMonth) {
                $startYearMonth = Carbon::parse($item->start_date)->format('Y-m');
                $endYearMonth = Carbon::parse($item->end_date)->format('Y-m');

                return $targetYearMonth >= $startYearMonth && $targetYearMonth <= $endYearMonth;
            })
            ->sortByDesc('start_date')
            ->first();
    }
}
