<?php

namespace App\Processors;

use Carbon\Carbon;
use App\Contracts\CalculateContract;
use Illuminate\Support\Str;

class PayslipProcessor implements CalculateContract
{
    public $payslip;

    public function __construct($identifier = null)
    {
        if (! is_null($identifier)) {
            if (is_string($identifier) || is_int($identifier)) {
                $this->payslip = config('open-payroll.models.payslip')::query()
                    ->with('earnings', 'deductions', 'payroll', 'employee')
                    ->whereId($identifier)
                    ->orWhere('hashslug', $identifier)
                    ->first();
            }
            if (is_object($identifier)) {
                $this->payslip($identifier);
            }
        }
    }

    public static function make($identifier = null)
    {
        return new self($identifier);
    }

    public function payslip($payslip)
    {
        $this->payslip = $payslip;

        return $this;
    }

    public function calculate()
    {
        if ($this->payslip) {
            $payroll = $this->payslip->payroll;
            $month = Carbon::parse($payroll->year . '-' . $payroll->month . '-1')->format('M');
            $year = date('Y', strtotime($payroll->year));
            $month_digit = Carbon::parse($payroll->year . '-' . $payroll->month . '-1')->format('m');
            $carbon_date = Carbon::createFromDate($year, $month_digit, 1);
            $total_days_in_month = $carbon_date->daysInMonth;
            $requested_date = $year.'-'.$month.'-'.$total_days_in_month;

            $employee   = $this->payslip->employee;
            $salary = $employee->salary;

            // Retrive the employee salary. CUSTOMCODE
            // $increment  = $employee->increment_details()
            //     ->where('increment_date', '<=', date('Y-m-d', strtotime($requested_date)))
            //     ->orderBy('increment_date', 'desc')
            //     ->first();
            // $salary     = $increment->basic_salary;

            $payroll    = $this->payslip->payroll;
            $earnings   = $this->payslip->earnings;
            $deductions = $this->payslip->deductions;

            $this->payslip->basic_salary = $gross_salary = $salary * 100;
            $class = config('open-payroll.processors.default_earning');
            if (class_exists($class)) {
                $gross_salary += $class::make($this->payslip)->calculate();
            }
            foreach ($earnings as $earning) {
                $class = config('open-payroll.processors.earnings.' . Str::studly($earning->type->name));
                if (class_exists($class)) {
                    $gross_salary += $class::make($earning)->calculate();
                } else {
                    $gross_salary += $earning->amount;
                }
            }

            $deduction_amount = 0;
            $class = config('open-payroll.processors.default_deduction');
            if (class_exists($class)) {
                $deduction_amount += $class::make($this->payslip)->calculate();
            }
            foreach ($deductions as $deduction) {
                $class = config('open-payroll.processors.deductions.' . Str::studly($deduction->type->name));
                if (class_exists($class)) {
                    $deduction_amount += $class::make($deduction)->calculate();
                } else {
                    $deduction_amount += $deduction->amount;
                }
            }

            $this->payslip->gross_salary = $gross_salary;
            $this->payslip->net_salary   = $gross_salary - $deduction_amount;

            $this->payslip->save();
        }

        return $this;
    }
}
