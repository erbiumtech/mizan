<?php

namespace App\Http\Controllers;

use App\Models\TransactionType;
use App\Services\PettyCashService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PettyCashController extends Controller
{
    public function __construct(private PettyCashService $pettyCash)
    {
    }

    public function show(Request $request)
    {
        abort_unless($request->user()->can('PettyCashView'), 403);

        $month = $this->month($request);

        return view('reports.petty-cash', [
            'summary' => $this->pettyCash->monthSummary($month),
            'monthValue' => $month->format('Y-m'),
            'types' => TransactionType::where('is_active', true)
                ->whereNotNull('account_id')
                ->whereNotIn('code', ['salary', 'petty-cash-replenishment'])
                ->orderBy('name')->get(),
            'canCreate' => $request->user()->can('PettyCashCreate'),
            'canReplenish' => $request->user()->can('PettyCashReplenish'),
            'pdf' => false,
        ]);
    }

    public function storeVoucher(Request $request)
    {
        abort_unless($request->user()->can('PettyCashCreate'), 403);

        $validated = $request->validate([
            'date' => 'required|date',
            'details' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'transaction_type_id' => 'required|integer|exists:transaction_types,id',
        ]);

        try {
            $voucher = $this->pettyCash->bookVoucher($validated);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('petty-cash.show', ['month' => Carbon::parse($validated['date'])->format('Y-m')])
            ->with('status', "Voucher {$voucher->voucher_no} booked.");
    }

    public function topUp(Request $request)
    {
        abort_unless($request->user()->can('PettyCashReplenish'), 403);

        $validated = $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $this->pettyCash->topUp($validated['date'], (float) $validated['amount']);

        return redirect()
            ->route('petty-cash.show', ['month' => Carbon::parse($validated['date'])->format('Y-m')])
            ->with('status', 'Float topped up.');
    }

    public function replenish(Request $request)
    {
        abort_unless($request->user()->can('PettyCashReplenish'), 403);

        $month = $this->month($request);

        try {
            $payment = $this->pettyCash->replenish($month);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['month' => $e->getMessage()]);
        }

        return redirect()
            ->route('petty-cash.show', ['month' => $month->format('Y-m')])
            ->with('status', "Replenishment payment #{$payment->id} for ".number_format($payment->amount, 2).' created — it will ride in the bank payment file.');
    }

    protected function month(Request $request): Carbon
    {
        $value = $request->validate(['month' => 'nullable|date_format:Y-m'])['month'] ?? now()->format('Y-m');

        return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
    }
}
