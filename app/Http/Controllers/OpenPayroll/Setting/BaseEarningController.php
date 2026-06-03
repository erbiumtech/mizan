<?php

namespace App\Http\Controllers\OpenPayroll\Setting;

use ErbiumTech\OpenPayroll\Contracts\CalculateContract;
use ErbiumTech\OpenPayroll\Traits\MakeInstance;

class BaseEarningController implements CalculateContract
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
    }
}
