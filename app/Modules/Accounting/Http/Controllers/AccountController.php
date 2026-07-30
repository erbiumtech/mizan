<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Modules\Accounting\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Modules\Accounting\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    /**
     * Flat list with filters: type, is_active, parent_id, search (code/name).
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->with('parent')
            ->withCount(['children', 'lines'])
            ->when($request->filled('type'), fn ($q) => $q->ofType($request->type))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('parent_id'), fn ($q) => $q->where('parent_id', $request->parent_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(fn ($sub) => $sub
                    ->where('code', 'like', "%{$request->search}%")
                    ->orWhere('name', 'like', "%{$request->search}%"));
            }) 
            ->orderBy('code')
            ->paginate($request->integer('per_page', 50));

        return AccountResource::collection($accounts);
    }

    /**
     * The chart of accounts as a nested tree with roll-up balances.
     */
    public function tree()
    {
        $this->authorize('viewAny', Account::class);

        $roots = Account::roots()
            ->with('childrenRecursive')
            ->orderBy('code')
            ->get();

        return AccountResource::collection($roots);
    }

    public function show(Account $account)
    {
        $this->authorize('view', $account);

        $account->load(['parent', 'children' => fn ($q) => $q->orderBy('code')])
            ->loadCount(['children', 'lines']);

        $lines = $account->lines()
            ->with('journalEntry:id,entry_number,entry_date,status,memo')
            ->latest('id')
            ->paginate(request()->integer('per_page', 25));

        return AccountResource::make($account)->additional([
            'lines' => $lines->items(),
            'lines_pagination' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'total' => $lines->total(),
            ],
        ]);
    }

    public function store(StoreAccountRequest $request)
    {
        $account = Account::create($request->validated());

        return AccountResource::make($account->load('parent'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        $account->update($request->validated());

        return AccountResource::make($account->fresh()->load('parent'));
    }

    public function destroy(Account $account)
    {
        $this->authorize('delete', $account);

        $account->delete();

        return response()->json(['message' => "Account {$account->code} deleted."]);
    }
}
