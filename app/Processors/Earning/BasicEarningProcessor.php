<?php

namespace App\Processors\Earning;

class BasicEarningProcessor extends BaseEarningProcessor
{
    public function calculate()
    {
        return $this->earning->amount;
    }
}
