<?php

namespace App\Processors\Earning;

use App\Contracts\CalculateContract;
use App\Traits\MakeInstance;

class BaseEarningProcessor implements CalculateContract
{
    use MakeInstance;

    public function getModel()
    {
        return config('open-payroll.models.earning');
    }

    public function earning($earning)
    {
        return $this->instance($earning);
    }

    public function calculate()
    {
    }
}
