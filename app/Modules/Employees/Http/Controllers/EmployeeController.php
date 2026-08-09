<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function myProfile(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee profile not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $employee,
        ], 200);
    }
}
