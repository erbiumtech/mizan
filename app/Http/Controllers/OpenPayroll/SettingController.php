<?php

namespace App\Http\Controllers\OpenPayroll;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __invoke(Request $request)
    {
        $earning_types   = \App\Models\Earning\Type::all();
        $deduction_types = \App\Models\Deduction\Type::all();

        return view('open-payroll.settings.index', compact('earning_types', 'deduction_types'));
    }
}
