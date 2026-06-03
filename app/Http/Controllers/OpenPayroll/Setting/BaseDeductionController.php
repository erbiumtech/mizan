<?php

namespace App\Http\Controllers\OpenPayroll\Setting;

use App\Models\OpenPayroll\Deduction;
use App\Models\OpenPayroll\DeductionType;
use Carbon\Carbon;
use ErbiumTech\OpenPayroll\Contracts\CalculateContract;
use ErbiumTech\OpenPayroll\Traits\MakeInstance;

class BaseDeductionController implements CalculateContract
{
    use MakeInstance;
    public $payslip;

    public function __construct($identifier = null)
    {
        $this->payslip = $identifier;
    }

    public function getModel()
    {
        return config('open-payroll.models.payslip');
    }

    public static function make($identifier = null)
    {
        return new self($identifier);
    }

    public function deduction($payslip)
    {
        $this->payslip = $payslip;

        return $this;
    }

    public function calculate()
    {
        $employee = $this->payslip->employee ?? null;
        $payroll = $this->payslip->payroll;
        $unpaidLeaveCount = $present_days = 0;
        $salary_details = null;

        if ($employee) {
            $total_days_in_month = cal_days_in_month(CAL_GREGORIAN, $payroll->month, $payroll->year);
            // // CUSTOMCODE
            // $requested_date = Carbon::now() . '-' . $total_days_in_month;

            // // Get the count of total unpaid leave of the employee in this month
            // $unpaidLeaveCount = \App\Http\Helpers\Utility::getLeaveOfEmployeeOfMonth($employee,'un-paid', $payroll->month, $payroll->year);

            // // Get the count of total present days of the employee in this month
            // $present_days = \App\Http\Helpers\Utility::getPresentDaysOfEmployeeInMonth($employee->employee_code, $payroll->month, $payroll->year);

            // // Get the salary details of the employee
            // $salary_details = $employee->increment_details()
            //     ->where('increment_date', '<=', date('Y-m-d', strtotime($requested_date)))
            //     ->orderBy('increment_date', 'desc')
            //     ->first();

            if ($salary_details) {
                $basic_salary = (int)$this->payslip->basic_salary;
                $total = (int)$basic_salary /  ($total_days_in_month);

                if ($unpaidLeaveCount == 0) {
                    $payable_salary = $total *  ($present_days);
                } else {
                    $payable_salary = $total *  ($present_days - $unpaidLeaveCount);
                    $salaryAfterLeave = ((int)$basic_salary - (int)$payable_salary) / 100;

                    $unpiadLeaveCode = config('open-payroll.codes.unpaid_leave');
                    $type = DeductionType::select('id', 'name')->where('code', $unpiadLeaveCode)->first();
                    if ($type) $this->addDeduction($this->payslip, $type, $salaryAfterLeave);
                }

                if ($payable_salary) {
                    $pfSalary = config('open-payroll.calculation.pf_payable_salary_is_greater_than') * 100;
                    if ($payable_salary >= $pfSalary) $pf_amount = $pfSalary;
                    else $pf_amount = $payable_salary;

                    $employee_pf = $professional_tax = $tds = $employee_esic = 0;

                    if ($salary_details->emp_type == 'Contract') {
                        if ($salary_details->tds_eligible == 1) {
                            $tdsCut = config('open-payroll.calculation.tds') / 100;
                            $tds = $payable_salary * $tdsCut;

                            $tdsCode = config('open-payroll.codes.TDS');
                            $type = DeductionType::select('id', 'name')->where('code', $tdsCode)->first();
                            if ($type) $this->addDeduction($this->payslip, $type, $tds);
                        }
                    }

                    if ($salary_details->esic_eligible == 1) {
                        // $employeerESICCut = config('open-payroll.calculation.employeer_esic') / 100;
                        // $employeer_esic = $payable_salary * $employeerESICCut;
                        $employeeESICCut = config('open-payroll.calculation.employee_esic') / 100;
                        $employee_esic = $payable_salary * $employeeESICCut;

                        $esicCode = config('open-payroll.codes.ESIC');
                        $type = DeductionType::select('id', 'name')->where('code', $esicCode)->first();
                        if ($type) $this->addDeduction($this->payslip, $type, $employee_esic);
                    }

                    if ($salary_details->pf_eligible == 1) {
                        // $employeerPFCut = config('open-payroll.calculation.employeer_pf') / 100;
                        // $employeer_pf = $pf_amount * $employeerPFCut;
                        $employeePFCut = config('open-payroll.calculation.employee_pf') / 100;
                        $employee_pf = $pf_amount * $employeePFCut;

                        $pfCode = config('open-payroll.codes.PF');
                        $type = DeductionType::select('id', 'name')->where('code', $pfCode)->first();
                        if ($type) $this->addDeduction($this->payslip, $type, $employee_pf);
                    }

                    if ($salary_details->pt_eligible == 1) {
                        $ptSalary = config('open-payroll.calculation.pt_payable_salary_is_greater_than') * 100;
                        if ($payable_salary >= $ptSalary) {
                            $PTCut = config('open-payroll.calculation.pt');
                            $professional_tax = $PTCut;

                            $ptCode = config('open-payroll.codes.PT');
                            $type = DeductionType::select('id', 'name')->where('code', $ptCode)->first();
                            if ($type) $this->addDeduction($this->payslip, $type, $professional_tax);
                        }
                    }
                }
            }
        }
    }

    protected function addDeduction($payslip, $type, $amount)
    {
        Deduction::updateOrCreate(
            [
                'user_id'           => $payslip->user_id,
                'payroll_id'        => $payslip->payroll_id,
                'payslip_id'        => $payslip->id,
                'deduction_type_id' => $type->id,
            ],
            [
                'name'              => $type->name,
                'description'       => $type->name,
                'amount'            => money()->toMachine($amount),
            ]
        );
    }
}
