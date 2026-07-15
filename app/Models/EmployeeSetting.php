<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

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
            if (empty($setting->end_date) && !empty($setting->start_date)) {
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
                $previousSetting->end_date = $newStart->copy()->subMonth()->endOfMonth()->toDateString();
                $previousSetting->save();
            }
        });

        static::updating(function ($setting) use ($setDefaultEndDate) {
            $setDefaultEndDate($setting);
        });
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
