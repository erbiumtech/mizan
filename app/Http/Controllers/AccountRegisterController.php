<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\RegisterEntryService;
use Illuminate\Http\Request;

class AccountRegisterController extends Controller
{
    public function __construct(private RegisterEntryService $register)
    {
    }

    public function show(Request $request, ?Account $account = null)
    {
        abort_unless($request->user()->can('JournalEntryView'), 403);

        $accounts = $this->register->registerAccounts();

        abort_if($accounts->isEmpty(), 404, 'No register accounts (postable 11xx cash/bank) found.');

        $account = $account && $accounts->contains('id', $account->id) ? $account : $accounts->first();

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $ledger = $this->register->registerRows($account, $validated['from'] ?? null, $validated['to'] ?? null);

        return view('reports.account-register', [
            'accounts' => $accounts,
            'account' => $account,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'ledger' => $ledger,
            'transferOptions' => $this->register->transferOptions($account),
            'canAdd' => $request->user()->can('JournalEntryCreate') && $request->user()->can('RegisterPost'),
            'pdf' => false,
        ]);
    }

    public function store(Request $request, Account $account)
    {
        abort_unless(
            $request->user()->can('JournalEntryCreate') && $request->user()->can('RegisterPost'),
            403
        );

        $validated = $request->validate([
            'date' => 'required|date',
            'description' => 'required|string|max:255',
            'transfer_account_id' => 'required|integer|exists:accounts,id',
            'debit' => 'nullable|numeric|min:0',
            'credit' => 'nullable|numeric|min:0',
            'num' => 'nullable|string|max:50',
        ]);

        $debit = (float) ($validated['debit'] ?? 0);
        $credit = (float) ($validated['credit'] ?? 0);

        if (($debit > 0) === ($credit > 0)) {
            return back()->withErrors(['debit' => 'Enter an amount in exactly one of Debit or Credit.'])->withInput();
        }

        $transfer = Account::findOrFail($validated['transfer_account_id']);

        try {
            $entry = $this->register->bookRow($account, $transfer, [
                'date' => $validated['date'],
                'description' => $validated['description'],
                'num' => $validated['num'] ?? null,
                'direction' => $debit > 0 ? 'in' : 'out',
                'amount' => $debit > 0 ? $debit : $credit,
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['transfer_account_id' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('register.show', ['account' => $account->id] + $request->only(['from', 'to']))
            ->with('status', "Booked {$entry->entry_number}.");
    }
}
