<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bank;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Policies\AccountPolicy;
use App\Modules\Accounting\Policies\BankPolicy;
use App\Modules\Accounting\Policies\BankStatementLinePolicy;
use App\Modules\Accounting\Policies\BankStatementPolicy;
use App\Modules\Accounting\Policies\BeneficiaryPolicy;
use App\Modules\Accounting\Policies\CompanyBankAccountPolicy;
use App\Modules\Accounting\Policies\FixedAssetPolicy;
use App\Modules\Accounting\Policies\JournalEntryLinePolicy;
use App\Modules\Accounting\Policies\JournalEntryPolicy;
use App\Modules\Accounting\Policies\PaymentPolicy;
use App\Modules\Accounting\Policies\TransactionTypePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Accounting module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class AccountingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Account::class => AccountPolicy::class,
        Bank::class => BankPolicy::class,
        BankStatementLine::class => BankStatementLinePolicy::class,
        BankStatement::class => BankStatementPolicy::class,
        Beneficiary::class => BeneficiaryPolicy::class,
        CompanyBankAccount::class => CompanyBankAccountPolicy::class,
        FixedAsset::class => FixedAssetPolicy::class,
        JournalEntryLine::class => JournalEntryLinePolicy::class,
        JournalEntry::class => JournalEntryPolicy::class,
        Payment::class => PaymentPolicy::class,
        TransactionType::class => TransactionTypePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
    }
}
