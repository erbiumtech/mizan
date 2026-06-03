<?php

namespace App\Jobs;

use App\Models\OpenPayroll\Employee;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PayslipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $employees;
    protected $payroll;
    /**
     * Create a new job instance.
     */
    public function __construct($employees, $payroll)
    {
        $this->employees = $employees;
        $this->payroll = $payroll;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $employees = $this->employees;
        $payroll = $this->payroll;

        $month = Carbon::parse($payroll->year . '-' . $payroll->month . '-1')->format('M');
        $year = date('Y', strtotime($payroll->year));
        $month_digit = Carbon::parse($payroll->year . '-' . $payroll->month . '-1')->format('m');
        $carbon_date = Carbon::createFromDate($year, $month_digit, 1);
        $total_days_in_month = $carbon_date->daysInMonth;
        $requested_date = $year.'-'.$month.'-'.$total_days_in_month;

        foreach ($employees as $employee) {
            $hashslug = $employee->hashslug;
            $employee = Employee::hashslug($hashslug)->firstOrFail();

            // Here, you can fetch the custom logic to retrive the employee's salary
            $payslip = $employee->payslips()->updateOrCreate([
                'payroll_id'   => $payroll->id,
                'basic_salary' => money()->toMachine($employee->salary),
                'gross_salary' => money()->toMachine($employee->salary),
                'net_salary'   => money()->toMachine($employee->salary),
                'is_verified'  => false,
                'is_approved'  => false,
                'is_locked'    => false,
            ]);

            // // CUSTOMCODE
            // $salary_details =  $employee->increment_details()
            //     ->where('increment_date', '<=', date('Y-m-d', strtotime($requested_date)))
            //     ->orderBy('increment_date', 'desc')
            //     ->first();

            // $payslip = $employee->payslips()->updateOrCreate([
            //     'payroll_id'   => $payroll->id,
            //     'basic_salary' => money()->toMachine($salary_details->basic_salary),
            //     'gross_salary' => money()->toMachine($salary_details->basic_salary),
            //     'net_salary'   => money()->toMachine($salary_details->basic_salary),
            //     'is_verified'  => false,
            //     'is_approved'  => false,
            //     'is_locked'    => false,
            // ]);

            $class = config('open-payroll.processors.default_deduction');
            if (class_exists($class)) {
                $class::make($payslip)->calculate();
            }
        }

        payroll($payroll->id)->calculate();
    }
}
