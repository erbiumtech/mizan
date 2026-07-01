<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payslip;
use App\Models\Employee;
use Spatie\LaravelPdf\Facades\Pdf;
use Illuminate\Support\Facades\Storage;

class PayslipController extends Controller
{
    public function index(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee profile not found'
            ], 404);
        }

        $payslips = Payslip::where('employee_id', $employee->id)
                           ->orderBy('id', 'desc')
                           ->get()
                           ->map(function ($payslip) use ($employee) {

                               $cleanPayPeriod = str_replace([' ', '/', '\\'], '-', $payslip->pay_period);

                               $fileName = 'payslips/' . $employee->employee_id . '-' . $cleanPayPeriod . '.pdf';

                               if (!Storage::disk('public')->exists($fileName)) {

                                   if (!Storage::disk('public')->exists('payslips')) {
                                       Storage::disk('public')->makeDirectory('payslips');
                                   }

                                   $absolutePath = Storage::disk('public')->path($fileName);

                                   Pdf::view('pdfs.payslip', ['data' => $payslip])
                                      ->format('a4')
                                      ->save($absolutePath);

                                   $payslip->update(['pdf_path' => $fileName]);
                               }

                               $payslip->pdf_url = url(Storage::url($fileName));

                               $payslip->setRelations([]);
                               $payslip->makeHidden('pdf_path');

                               return $payslip;
                           });

        return response()->json([
            'success' => true,
            'count' => $payslips->count(),
            'data' => $payslips
        ], 200);
    }
}
