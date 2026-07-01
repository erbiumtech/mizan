<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Employee;

class EmployeeController extends Controller
{
    public function myProfile(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee profile not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $employee
        ], 200);
    }
}
