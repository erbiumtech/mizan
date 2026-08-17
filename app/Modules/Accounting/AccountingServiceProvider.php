<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Console\Commands\BackfillPaymentEntriesCommand;
use App\Modules\Accounting\Console\Commands\RaiseScheduledTransactions;
use App\Modules\Accounting\Console\Commands\RaiseSubscriptionPayments;
use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Filament\Pages\BalanceSheet;
use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Accounting\Filament\Pages\BudgetVsActual;
use App\Modules\Accounting\Filament\Pages\CashFlow;
use App\Modules\Accounting\Filament\Pages\ContractorPayments;
use App\Modules\Accounting\Filament\Pages\CurrencyRevaluation;
use App\Modules\Accounting\Filament\Pages\FindTransactions;
use App\Modules\Accounting\Filament\Pages\GeneralLedger;
use App\Modules\Accounting\Filament\Pages\PettyCashBook;
use App\Modules\Accounting\Filament\Pages\ProfitAndLoss;
use App\Modules\Accounting\Filament\Pages\TrialBalance;
use App\Modules\Accounting\Filament\Settings\CurrencySettingsSection;
use App\Modules\Accounting\Filament\Settings\PayrollPostingSettingsSection;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Models\LoanInstalment;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Accounting\Models\ScheduledTransactionLine;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Policies\AccountPolicy;
use App\Modules\Accounting\Policies\BankPolicy;
use App\Modules\Accounting\Policies\BankStatementLinePolicy;
use App\Modules\Accounting\Policies\BankStatementPolicy;
use App\Modules\Accounting\Policies\BeneficiaryPolicy;
use App\Modules\Accounting\Policies\BeneficiarySubscriptionPolicy;
use App\Modules\Accounting\Policies\BudgetLinePolicy;
use App\Modules\Accounting\Policies\BudgetPolicy;
use App\Modules\Accounting\Policies\CompanyBankAccountPolicy;
use App\Modules\Accounting\Policies\CurrencyPolicy;
use App\Modules\Accounting\Policies\FixedAssetPolicy;
use App\Modules\Accounting\Policies\JournalEntryLinePolicy;
use App\Modules\Accounting\Policies\JournalEntryPolicy;
use App\Modules\Accounting\Policies\LoanInstalmentPolicy;
use App\Modules\Accounting\Policies\LoanPolicy;
use App\Modules\Accounting\Policies\PaymentPolicy;
use App\Modules\Accounting\Policies\ScheduledTransactionLinePolicy;
use App\Modules\Accounting\Policies\ScheduledTransactionPolicy;
use App\Modules\Accounting\Policies\TransactionTypePolicy;
use App\Modules\Accounting\Services\FiscalYearClosingService;
use App\Modules\Accounting\Support\OpeningBalanceCsvImporter;
use App\Modules\Accounting\Support\ReportPane;
use App\Modules\Core\Models\Bank;
use App\Support\Contracts\FiscalYearCloseCheck;
use App\Support\CsvImporters;
use App\Support\CustomFieldSubjects;
use App\Support\DashboardStats;
use App\Support\JournalEntryOwners;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\SettingsSections;
use Filament\Widgets\StatsOverviewWidget\Stat;
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
        Budget::class => BudgetPolicy::class,
        BudgetLine::class => BudgetLinePolicy::class,
        Currency::class => CurrencyPolicy::class,
        BeneficiarySubscription::class => BeneficiarySubscriptionPolicy::class,
        CompanyBankAccount::class => CompanyBankAccountPolicy::class,
        FixedAsset::class => FixedAssetPolicy::class,
        JournalEntryLine::class => JournalEntryLinePolicy::class,
        JournalEntry::class => JournalEntryPolicy::class,
        Loan::class => LoanPolicy::class,
        LoanInstalment::class => LoanInstalmentPolicy::class,
        Payment::class => PaymentPolicy::class,
        ScheduledTransaction::class => ScheduledTransactionPolicy::class,
        ScheduledTransactionLine::class => ScheduledTransactionLinePolicy::class,
        TransactionType::class => TransactionTypePolicy::class,
    ];

    public function boot(): void
    {
        // This module's reports in the Reports hub. Registered rather than listed in Core, which
        // used to name all eighteen — see App\Support\Reporting\ReportCatalogue.
        ReportCatalogue::register('Financial statements', BalanceSheet::class, 'What the company owns, owes and is worth, on a date.');
        ReportCatalogue::register('Financial statements', ProfitAndLoss::class, 'Income less expenses over a period, and the profit that leaves.');
        ReportCatalogue::register('Financial statements', CashFlow::class, 'Where the money actually came from and went, period by period.');
        ReportCatalogue::register('Financial statements', TrialBalance::class, 'Every account with its balance, and the proof that the books add up.');
        ReportCatalogue::register('Financial statements', GeneralLedger::class, 'Every account, every entry against it, opening to closing — what an audit reads.');
        ReportCatalogue::register('Financial statements', BudgetVsActual::class, 'What was planned against what was spent, by account and by month.');
        ReportCatalogue::register('Receivables & payables', ContractorPayments::class, 'What each contractor has been paid, and over what period.');
        ReportCatalogue::register('Ledgers & books', AccountRegister::class, 'One account, every transaction against it, running balance — and edits.');
        ReportCatalogue::register('Ledgers & books', FindTransactions::class, 'Search the whole ledger by account, date, amount or wording.');
        ReportCatalogue::register('Ledgers & books', PettyCashBook::class, 'The cash float: what was spent, what is left, and replenishment.');
        ReportCatalogue::register('Ledgers & books', CurrencyRevaluation::class, 'Foreign balances at the rate on a date, and the difference posted.');
        ReportCatalogue::register('Bank files', BankPaymentFile::class, 'Selected payments as a bank transfer file.');

        // The records of this module that may carry custom fields. Registered by alias, which is what
        // `custom_fields.model_type` stores — see App\Support\CustomFieldSubjects.
        CustomFieldSubjects::register(ModuleMap::alias(Beneficiary::class), 'Beneficiaries');
        CustomFieldSubjects::register(ModuleMap::alias(FixedAsset::class), 'Fixed Assets');

        // A trial balance from the company's old system, at setup. Core reads the CSV; deciding what
        // balances, and posting the one entry it becomes, is accounting — see App\Support\CsvImporters.
        // Last, because it is the one that needs the chart of accounts to exist first.
        CsvImporters::register('opening_balances', OpeningBalanceCsvImporter::class, 30);

        // This module's blocks of the Company Settings screen, which Core used to write itself and so needed
        // `Currency`, `JournalEntryLine` and `Account` for — see App\Support\SettingsSections. Currency first
        // on that page: it is what every other figure there means.
        SettingsSections::register('accounting.currency', CurrencySettingsSection::class, 10);
        SettingsSections::register('accounting.payroll-posting', PayrollPostingSettingsSection::class, 60);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // The documents Accounting itself books entries for. The register refuses to edit an entry any of
        // these owns, because the entry is the accounting half of that document and the two would
        // desynchronise silently. Invoicing and Inventory register their own — see
        // App\Support\JournalEntryOwners for why this is a registry rather than a list.
        // The real fiscal-year close, replacing the null default bound in ContractDefaultsServiceProvider.
        $this->app->bind(FiscalYearCloseCheck::class, FiscalYearClosingService::class);
        $this->app->bind(ReportPaneRenderer::class, ReportPane::class);

        JournalEntryOwners::register('a payment', Payment::class);
        JournalEntryOwners::register('a petty cash voucher', PettyCashVoucher::class);
        JournalEntryOwners::register('a fixed asset', FixedAsset::class);

        DashboardStats::register('accounting.pending-entries', fn () => auth()->user()?->can('JournalEntryApprove')
            ? Stat::make(
                'Journal Entries Awaiting Approval',
                JournalEntry::where('status', JournalEntry::STATUS_PENDING)->count(),
            )->description('pending')
            : null, sort: 20);

        $this->commands([
            BackfillPaymentEntriesCommand::class,
            RaiseScheduledTransactions::class,
            RaiseSubscriptionPayments::class,
        ]);

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/routes/console.php');
    }
}
