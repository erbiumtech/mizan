<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Http\Request;

class PayslipController extends Controller
{
    /**
     * The signed-in employee's payslips.
     *
     * `pdf_url` names the download route rather than a file on the public disk.
     * Two things were wrong with the file: it was written once and reused for
     * ever, so a payslip corrected afterwards kept handing out the old figures;
     * and its name was built from a `pay_period` attribute that does not exist,
     * which left the month out and collapsed every month of a fiscal year onto one
     * file per employee — August's download was July's PDF.
     *
     * Listing no longer renders anything either. It used to render a PDF for every
     * payslip missing one, so the first call of a new fiscal year rendered a year
     * of them before it answered.
     */
    public function index(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee profile not found',
            ], 404);
        }

        $payslips = Payslip::where('employee_id', $employee->id)
            ->with('fiscalYear')
            ->orderByDesc('id')
            ->get()
            ->map(function (Payslip $payslip) {
                $payslip->pdf_url = route('payslips.pdf', ['payslip' => $payslip->id]);

                $payslip->setRelations([]);
                $payslip->makeHidden('pdf_path');

                return $payslip;
            });

        return response()->json([
            'success' => true,
            'count' => $payslips->count(),
            'data' => $payslips,
        ], 200);
    }

    /**
     * One payslip as a PDF, rendered now from the payslip as it stands.
     *
     * Scoped to the caller's own payslips. The id is in the URL, so without this
     * an employee could read a colleague's salary by changing a number.
     */
    public function pdf(Request $request, Payslip $payslip)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        abort_if(! $employee || $payslip->employee_id !== $employee->id, 403);

        return app(PayslipService::class)->renderPdf($payslip);
    }
}
