<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\GnuCashImportService;
use App\Services\RegisterEntryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GnuCashImportController extends Controller
{
    public function __construct(
        private GnuCashImportService $import,
        private RegisterEntryService $register,
    ) {
    }

    public function show(Request $request)
    {
        abort_unless($request->user()->can('GnuCashImport'), 403);

        return view('reports.gnucash-import', [
            'registerAccounts' => $this->register->registerAccounts(),
            'preview' => session('gnucash_preview'),
            'token' => session('gnucash_token'),
            'result' => session('gnucash_result'),
            'pdf' => false,
        ]);
    }

    /**
     * Upload + dry-run preview. The file is kept on disk under a token;
     * nothing is written until confirm.
     */
    public function preview(Request $request)
    {
        abort_unless($request->user()->can('GnuCashImport'), 403);

        $request->validate([
            'csv' => 'required|file|mimes:csv,txt|max:10240',
            'target_account_id' => 'nullable|integer|exists:accounts,id',
        ]);

        $csv = $request->file('csv')->get();
        $target = $request->filled('target_account_id')
            ? Account::find($request->integer('target_account_id'))
            : null;

        try {
            $preview = $this->import->preview($csv, $target);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['csv' => $e->getMessage()]);
        }

        $token = Str::uuid()->toString();
        Storage::put("gnucash-imports/{$token}.csv", $csv);

        return back()->with([
            'gnucash_preview' => $preview,
            'gnucash_token' => $token.($target ? ':'.$target->id : ''),
        ]);
    }

    public function confirm(Request $request)
    {
        abort_unless($request->user()->can('GnuCashImport'), 403);

        $request->validate(['token' => 'required|string']);

        [$token, $targetId] = array_pad(explode(':', $request->string('token')), 2, null);
        $path = 'gnucash-imports/'.basename($token).'.csv';

        abort_unless(Storage::exists($path), 404, 'Uploaded file expired — upload again.');

        $target = $targetId ? Account::find($targetId) : null;

        try {
            $result = $this->import->import(Storage::get($path), $target);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['csv' => $e->getMessage()]);
        } finally {
            Storage::delete($path);
        }

        activity('gnucash-import')
            ->causedBy($request->user())
            ->withProperties($result)
            ->log('GnuCash import: '.json_encode($result));

        return redirect()->route('gnucash.show')->with('gnucash_result', $result);
    }
}
