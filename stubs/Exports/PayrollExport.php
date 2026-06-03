<?php

namespace App\Exports;

use App\Models\OpenPayroll\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PayrollExport implements FromCollection, WithHeadings
{
    /**
     * @return \Illuminate\Support\Collection
     */
    protected $id;

    function __construct($id) {
        $this->id = $id;
    }

    public function collection()
    {
        $payroll = Payroll::whereHashslug($this->id)->firstOrFail();
        $srNo = 1;
        $result = [];

        foreach($payroll->payslips as $payslip)
        {
            $employee = $payslip->employee;
            $basicSalary = $payslip->basic_salary;
            $totalDaysInMonth = cal_days_in_month(CAL_GREGORIAN, $payroll->month, $payroll->year);
            // $month = Carbon::parse($payroll->year . '-' . $payroll->month . '-1')->format('m');
            // $year = date('Y', strtotime($payroll->year));
            // $requested_date = $year."-".$month."-".$totalDaysInMonth;

            $presentDays = $paidLeaves = $unpaidLeaves = 0;
            // CUSTOMCODE
            // You need to calculate the total present days, paid leave taken, unpaid leave, of the employee in payroll month and year

            // $presentDays = isset($payslip->payroll) && !empty($employee) ? \App\Http\Helpers\Utility::getPresentDaysOfEmployeeInMonth($employee->employee_code,$payslip->payroll->month, $payslip->payroll->year) : 0;
            // $paidLeaves = !empty($employee) ? \App\Http\Helpers\Utility::getLeaveOfEmployeeOfMonth($employee,'paid', $payslip->payroll->month, $payslip->payroll->year) : 0;
            // $unpaidLeaves = !empty($employee) ? \App\Http\Helpers\Utility::getLeaveOfEmployeeOfMonth($employee,'un-paid', $payslip->payroll->month, $payslip->payroll->year) : 0;

            if ($presentDays == 0 && $paidLeaves == 0 && $unpaidLeaves == 0) {
                Log::warning('You need to calculate the total present days, paid leave taken, unpaid leave, of the employee in payroll month and year');
            }

            $perDaySalary = $payslip->basic_salary / $totalDaysInMonth;
            $salaryAfterLeave = $perDaySalary * ($presentDays - $unpaidLeaves);
            $pfCut = config('open-payroll.calculation.pf_payable_salary_is_greater_than') * 100;
            $pfDeduction = $salaryAfterLeave >=  $pfCut? $pfCut : $salaryAfterLeave;

            $salary_details = $employee->salary;
            // $salary_details = $employee->increment_details()
            //     ->where('increment_date','<=',date('Y-m-d',strtotime($requested_date)))
            //     ->orderBy( 'increment_date', 'desc' )
            //     ->first();

            $esicEligible = !empty($salary_details) && $salary_details->esic_eligible == 1;
            $pfEligible = !empty($salary_details) && $salary_details->pf_eligible == 1;
            $ptEligible = !empty($salary_details) && $salary_details->pt_eligible == 1;
            $tdsEligible = !empty($salary_details) && $salary_details->tds_eligible == 1;

            $result[] = array(
                'serial_no' => $srNo,
                'employee_id'=> !empty($employee) ? $employee->id : '',
                'employee_code'=> !empty($employee) ? $employee->employee_code : '',
                'employee_name' => (!empty($employee)) ? $employee->first_name.' '.$payslip->employee->last_name : '',
                'employee_type'=> !empty($employee) ? $employee->type_emp : '',
                // 'gross_salary' =>  money()->toHuman($payslip->gross_salary),
                'total_days_in_month' => $totalDaysInMonth,
                'present_days' => $presentDays,
                'per_day_salary' => money()->toHuman($perDaySalary),
                'paid_leaves' => $paidLeaves,
                'unpaid_leaves' => $unpaidLeaves,
                'leave_balance' => !empty($employee) ? $employee->leave_balance : 0,
                'present_days_after_leave' => $presentDays - $paidLeaves - $unpaidLeaves,
                'basic_salary' => money()->toHuman($basicSalary),
                'salary_after_leave' => money()->toHuman($salaryAfterLeave),
                'esic_eligible' => $esicEligible ? 'Yes' : 'No',
                'employer_esic' => $esicEligible ? money()->toHuman($salaryAfterLeave * config('open-payroll.calculation.employeer_esic')): 0,
                'employee_esic' => $esicEligible ? money()->toHuman($salaryAfterLeave * config('open-payroll.calculation.employee_esic')) : 0,
                'pf_deduction' => $pfEligible ? money()->toHuman($pfDeduction) : 0,
                'pf_eligible' => $pfEligible ? 'Yes' : 'No',
                'employer_pf' => $pfEligible ? money()->toHuman($pfDeduction * config('open-payroll.calculation.employeer_pf')) : 0,
                'employee_pf' => $pfEligible ? money()->toHuman($pfDeduction * config('open-payroll.calculation.employee_esic')) : 0,
                'pt_eligible' => $ptEligible ? 'Yes' : 'No',
                'profession_tax' => $ptEligible ? money()->toHuman($salaryAfterLeave >= config('open-payroll.calculation.pt_payable_salary_is_greater_than') * 100 ? config('open-payroll.calculation.pt') * 100 : 0) : 0,
                'tds_eligible' => $tdsEligible ? 'Yes' : 'No',
                'tds' => $tdsEligible ? money()->toHuman($salaryAfterLeave * config('open-payroll.calculation.tds')) : 0,
                'net_salary' =>  money()->toHuman($payslip->net_salary),
                'ctc' => money()->toHuman($basicSalary + ($esicEligible ? $salaryAfterLeave * config('open-payroll.calculation.employeer_esic') : 0) + ($pfEligible ? $pfDeduction * config('open-payroll.calculation.employeer_pf') : 0)),
                'account_no' => !empty($employee->bank_acc_no) ? $employee->bank_acc_no : '-',
                'ifsc_code' => !empty($employee->IFSC_code) ? $employee->IFSC_code : '-',
                'bank_name' => !empty($employee->getBank) ? $employee->getBank->name : '-',
                'mobile_no' => !empty($employee->mobile_number) ? $employee->mobile_number : '-',
            );
            $srNo++;
        }

        return collect($result);
    }

    public function headings(): array
    {
        return [
            "Serial Number",
            "Employee ID",
            "Employee Code",
            "Employee Name",
            "Employee Type",
            // "Gross Salary",
            "Total Days in Month",
            "Present Days",
            "Per Day Salary",
            "Paid Leave",
            "Unpaid Leave",
            "Leave Balance",
            "Present days After Leave",
            "Basic Salary",
            "Salary After Unpaid Leave",
            "ESIC Eligible",
            "Employer ESIC" ,
            "Employee ESIC" ,
            "PF Eligible Salary (Only apply if PF Yes)",
            "PF Eligible",
            "Employer PF",
            "Employee PF",
            "PT Eligible",
            "Profession Tax",
            "TDS Eligible",
            "TDS",
            "Net Salary" ,
            "CTC",
            "Account No",
            "IFSC Code",
            "Bank Name",
            "Mobile No"
        ];
    }
}
