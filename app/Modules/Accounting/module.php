<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'accounting',
    'label' => 'Accounting',
    'description' => 'Chart of accounts, journal entries, payments, banks, fixed assets, petty cash and financial reports.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Accounting\AccountingPlugin::class,

    'models' => [
        'App\\Models\\Account' => \App\Modules\Accounting\Models\Account::class,
        'App\\Models\\Currency' => \App\Modules\Accounting\Models\Currency::class,
        'App\\Models\\ExchangeRate' => \App\Modules\Accounting\Models\ExchangeRate::class,
        'App\\Models\\JournalEntry' => \App\Modules\Accounting\Models\JournalEntry::class,
        'App\\Models\\JournalEntryLine' => \App\Modules\Accounting\Models\JournalEntryLine::class,
        'App\\Models\\TransactionType' => \App\Modules\Accounting\Models\TransactionType::class,
        'App\\Models\\CompanyBankAccount' => \App\Modules\Accounting\Models\CompanyBankAccount::class,
        'App\\Models\\Beneficiary' => \App\Modules\Accounting\Models\Beneficiary::class,
        'App\\Models\\Budget' => \App\Modules\Accounting\Models\Budget::class,
        'App\\Models\\BudgetLine' => \App\Modules\Accounting\Models\BudgetLine::class,
        'App\\Models\\BeneficiarySubscription' => \App\Modules\Accounting\Models\BeneficiarySubscription::class,
        'App\\Models\\Payment' => \App\Modules\Accounting\Models\Payment::class,
        'App\\Models\\FixedAsset' => \App\Modules\Accounting\Models\FixedAsset::class,
        'App\\Models\\BankStatement' => \App\Modules\Accounting\Models\BankStatement::class,
        'App\\Models\\BankStatementLine' => \App\Modules\Accounting\Models\BankStatementLine::class,
        'App\\Models\\PettyCashVoucher' => \App\Modules\Accounting\Models\PettyCashVoucher::class,
        'App\\Models\\Loan' => \App\Modules\Accounting\Models\Loan::class,
        'App\\Models\\LoanInstalment' => \App\Modules\Accounting\Models\LoanInstalment::class,
        'App\\Models\\ScheduledTransaction' => \App\Modules\Accounting\Models\ScheduledTransaction::class,
        'App\\Models\\ScheduledTransactionLine' => \App\Modules\Accounting\Models\ScheduledTransactionLine::class,

        // Tax withheld at source from suppliers — docs/erpnext-gap-plan.md Phase 4. The rate table is
        // reference data (WithholdingSectionSeeder) and the deductions are what the §165 statement reads.
        'App\\Models\\WithholdingSection' => \App\Modules\Accounting\Models\WithholdingSection::class,
        'App\\Models\\WithholdingDeduction' => \App\Modules\Accounting\Models\WithholdingDeduction::class,

        // The AI command bot — docs/ai-command-bot-plan.md. Here rather than in a module of its own
        // because what it produces is a register row: it is a second way into Accounting, not a domain.
        'App\\Models\\CommandUtterance' => \App\Modules\Accounting\Models\CommandUtterance::class,
        'App\\Models\\TransactionTypeAlias' => \App\Modules\Accounting\Models\TransactionTypeAlias::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Accounts\\AccountResource' => \App\Modules\Accounting\Filament\Resources\Accounts\AccountResource::class,
        'App\\Filament\\Resources\\Currencies\\CurrencyResource' => \App\Modules\Accounting\Filament\Resources\Currencies\CurrencyResource::class,
        'App\\Filament\\Resources\\JournalEntries\\JournalEntryResource' => \App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource::class,
        'App\\Filament\\Resources\\JournalEntryLines\\JournalEntryLineResource' => \App\Modules\Accounting\Filament\Resources\JournalEntryLines\JournalEntryLineResource::class,
        'App\\Filament\\Resources\\TransactionTypes\\TransactionTypeResource' => \App\Modules\Accounting\Filament\Resources\TransactionTypes\TransactionTypeResource::class,
        'App\\Filament\\Resources\\Banks\\BankResource' => \App\Modules\Accounting\Filament\Resources\Banks\BankResource::class,
        'App\\Filament\\Resources\\CompanyBankAccounts\\CompanyBankAccountResource' => \App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource::class,
        'App\\Filament\\Resources\\Beneficiaries\\BeneficiaryResource' => \App\Modules\Accounting\Filament\Resources\Beneficiaries\BeneficiaryResource::class,
        'App\\Filament\\Resources\\Budgets\\BudgetResource' => \App\Modules\Accounting\Filament\Resources\Budgets\BudgetResource::class,
        'App\\Filament\\Resources\\Payments\\PaymentResource' => \App\Modules\Accounting\Filament\Resources\Payments\PaymentResource::class,
        'App\\Filament\\Resources\\FixedAssets\\FixedAssetResource' => \App\Modules\Accounting\Filament\Resources\FixedAssets\FixedAssetResource::class,
        'App\\Filament\\Resources\\BankStatements\\BankStatementResource' => \App\Modules\Accounting\Filament\Resources\BankStatements\BankStatementResource::class,
        'App\\Filament\\Resources\\BankStatementLines\\BankStatementLineResource' => \App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource::class,
        'App\\Filament\\Resources\\Loans\\LoanResource' => \App\Modules\Accounting\Filament\Resources\Loans\LoanResource::class,
        'App\\Filament\\Resources\\ScheduledTransactions\\ScheduledTransactionResource' => \App\Modules\Accounting\Filament\Resources\ScheduledTransactions\ScheduledTransactionResource::class,
    ],

    'pages' => [
        // The loan book (Phase 1.6), the forward cash view (1.7), the asset register (2.5) and the
        // bank reconciliation statement (2.6).
        'App\\Filament\\Pages\\BankReconciliationStatement' => \App\Modules\Accounting\Filament\Pages\BankReconciliationStatement::class,
        'App\\Filament\\Pages\\FixedAssetRegister' => \App\Modules\Accounting\Filament\Pages\FixedAssetRegister::class,
        'App\\Filament\\Pages\\CashCommitments' => \App\Modules\Accounting\Filament\Pages\CashCommitments::class,
        'App\\Filament\\Pages\\LoansOutstanding' => \App\Modules\Accounting\Filament\Pages\LoansOutstanding::class,
        'App\\Filament\\Pages\\AccountRegister' => \App\Modules\Accounting\Filament\Pages\AccountRegister::class,
        'App\\Filament\\Pages\\FindTransactions' => \App\Modules\Accounting\Filament\Pages\FindTransactions::class,
        'App\\Filament\\Pages\\CashFlow' => \App\Modules\Accounting\Filament\Pages\CashFlow::class,
        'App\\Filament\\Pages\\ContractorPayments' => \App\Modules\Accounting\Filament\Pages\ContractorPayments::class,
        'App\\Filament\\Pages\\CurrencyRevaluation' => \App\Modules\Accounting\Filament\Pages\CurrencyRevaluation::class,
        'App\\Filament\\Pages\\BalanceSheet' => \App\Modules\Accounting\Filament\Pages\BalanceSheet::class,
        'App\\Filament\\Pages\\BudgetVsActual' => \App\Modules\Accounting\Filament\Pages\BudgetVsActual::class,
        'App\\Filament\\Pages\\TrialBalance' => \App\Modules\Accounting\Filament\Pages\TrialBalance::class,
        'App\\Filament\\Pages\\GeneralLedger' => \App\Modules\Accounting\Filament\Pages\GeneralLedger::class,
        'App\\Filament\\Pages\\ProfitAndLoss' => \App\Modules\Accounting\Filament\Pages\ProfitAndLoss::class,
        'App\\Filament\\Pages\\ProfitAndLossByDimension' => \App\Modules\Accounting\Filament\Pages\ProfitAndLossByDimension::class,
        'App\\Filament\\Pages\\WithholdingStatement' => \App\Modules\Accounting\Filament\Pages\WithholdingStatement::class,
        'App\\Filament\\Pages\\GnuCashImport' => \App\Modules\Accounting\Filament\Pages\GnuCashImport::class,
        'App\\Filament\\Pages\\PettyCashBook' => \App\Modules\Accounting\Filament\Pages\PettyCashBook::class,
        'App\\Filament\\Pages\\BankPaymentFile' => \App\Modules\Accounting\Filament\Pages\BankPaymentFile::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\AccountBalancesOverview' => \App\Modules\Accounting\Filament\Widgets\AccountBalancesOverview::class,
        'App\\Filament\\Widgets\\CashFlowChart' => \App\Modules\Accounting\Filament\Widgets\CashFlowChart::class,
        'App\\Filament\\Widgets\\CashCommittedOverview' => \App\Modules\Accounting\Filament\Widgets\CashCommittedOverview::class,
        'App\\Filament\\Widgets\\RevenueAndExpensesChart' => \App\Modules\Accounting\Filament\Widgets\RevenueAndExpensesChart::class,
    ],

    // What the report builder may report on — reports-expansion-plan.md Phase 6, item 1. A dataset is
    // declared by the module that owns the subject, so it arrives with a module to gate on.
    'datasets' => [
        'App\\Reporting\\JournalLineDataset' => \App\Modules\Accounting\Reporting\JournalLineDataset::class,
    ],

    'permission_groups' => [
        'Account',
        'Bank',
        'BankStatement',
        'Beneficiary',
        'CompanyBankAccount',
        'Budget',
        'FixedAsset',
        'JournalEntry',
        'Loan',
        'Payment',
        'TransactionType',
        'PettyCash',
        'Report',
        'Register',
        'Import',
    ],

    'permissions' => [
        ['name' => 'AccountView', 'group' => 'Account'],
        ['name' => 'AccountCreate', 'group' => 'Account'],
        ['name' => 'AccountUpdate', 'group' => 'Account'],
        ['name' => 'AccountDelete', 'group' => 'Account'],
        ['name' => 'ReportView', 'group' => 'Report'],
        /*
         * The report builder — reports-expansion-plan.md Phase 6, item 6.
         *
         * Here rather than in Core because `ReportView` is here: the Report group has one owner, and
         * `ModuleAuthorization` resolves a bare permission's module *through its group*, so splitting the
         * group across two modules would make "which module gates this check" depend on which manifest
         * declared which name.
         *
         * `ReportBuild` is composing one at all. Reading stays `ReportView`, which every report in the
         * application is already gated on — a custom report is a report.
         *
         * `ReportShare` is the toggle that makes one visible to the whole company, and it is separate
         * because the item says so and the reason is concrete: a company-wide custom report over payslips
         * would be a payroll leak. Granted to nobody below Administrator. Note that it is not the *only*
         * thing standing in the way — reading a definition resolves its subject through the reader's own
         * module and permission gates, so a shared payslip report shows a person nothing they could not
         * already open. See App\Modules\Core\Models\ReportDefinition.
         */
        ['name' => 'ReportBuild', 'group' => 'Report'],
        ['name' => 'ReportShare', 'group' => 'Report'],
        /*
         * Scheduled reports — reports-expansion-plan.md Phase 8, items 1 and 2.
         *
         * In the same group as the rest, for the reason above: the Report group has one owner. Four
         * permissions rather than one because a schedule is a row somebody keeps — the usual
         * view/create/update/delete — and the update and delete ones are additionally scoped to the *owner*
         * by `ReportSchedulePolicy`, since a schedule renders with its owner's access and editing somebody
         * else's recipient list would be sending their rows to a list they never agreed to.
         *
         * `ReportSendExternal` is the one that is not a CRUD verb, and item 2 is explicit about why:
         * "external recipients need their own permission". An address that matches nobody in this company is
         * a report leaving the company, decided by the person whose access produced the rows. Granted to
         * nobody below Administrator.
         */
        ['name' => 'ReportScheduleView', 'group' => 'Report'],
        ['name' => 'ReportScheduleCreate', 'group' => 'Report'],
        ['name' => 'ReportScheduleUpdate', 'group' => 'Report'],
        ['name' => 'ReportScheduleDelete', 'group' => 'Report'],
        ['name' => 'ReportSendExternal', 'group' => 'Report'],
        // Planning, separate from ReportView: the budget says what the
        // company intends to do, which is not the same thing as being
        // allowed to read what it has already done.
        ['name' => 'BudgetView', 'group' => 'Budget'],
        ['name' => 'BudgetCreate', 'group' => 'Budget'],
        ['name' => 'BudgetUpdate', 'group' => 'Budget'],
        ['name' => 'BudgetDelete', 'group' => 'Budget'],
        // Borrowings and their repayment schedules. LoanRecord is the one
        // that writes to the ledger, so it is separated from Update the way
        // posting is separated from editing everywhere else here.
        ['name' => 'LoanView', 'group' => 'Loan'],
        ['name' => 'LoanCreate', 'group' => 'Loan'],
        ['name' => 'LoanUpdate', 'group' => 'Loan'],
        ['name' => 'LoanDelete', 'group' => 'Loan'],
        ['name' => 'LoanRecord', 'group' => 'Loan'],
        ['name' => 'BankView', 'group' => 'Bank'],
        ['name' => 'BankCreate', 'group' => 'Bank'],
        ['name' => 'BankUpdate', 'group' => 'Bank'],
        ['name' => 'BankDelete', 'group' => 'Bank'],
        ['name' => 'TransactionTypeView', 'group' => 'TransactionType'],
        ['name' => 'TransactionTypeCreate', 'group' => 'TransactionType'],
        ['name' => 'TransactionTypeUpdate', 'group' => 'TransactionType'],
        ['name' => 'TransactionTypeDelete', 'group' => 'TransactionType'],
        ['name' => 'CompanyBankAccountView', 'group' => 'CompanyBankAccount'],
        ['name' => 'CompanyBankAccountCreate', 'group' => 'CompanyBankAccount'],
        ['name' => 'CompanyBankAccountUpdate', 'group' => 'CompanyBankAccount'],
        ['name' => 'CompanyBankAccountDelete', 'group' => 'CompanyBankAccount'],
        ['name' => 'BeneficiaryView', 'group' => 'Beneficiary'],
        ['name' => 'BeneficiaryCreate', 'group' => 'Beneficiary'],
        ['name' => 'BeneficiaryUpdate', 'group' => 'Beneficiary'],
        ['name' => 'BeneficiaryDelete', 'group' => 'Beneficiary'],
        ['name' => 'PaymentView', 'group' => 'Payment'],
        ['name' => 'PaymentCreate', 'group' => 'Payment'],
        ['name' => 'PaymentUpdate', 'group' => 'Payment'],
        ['name' => 'PaymentDelete', 'group' => 'Payment'],
        ['name' => 'RegisterPost', 'group' => 'Register'],
        ['name' => 'GnuCashImport', 'group' => 'Import'],
        ['name' => 'PettyCashView', 'group' => 'PettyCash'],
        ['name' => 'PettyCashCreate', 'group' => 'PettyCash'],
        ['name' => 'PettyCashReplenish', 'group' => 'PettyCash'],
        ['name' => 'JournalEntryView', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntryCreate', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntryUpdate', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntryDelete', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntrySubmit', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntryApprove', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntryReject', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntryPost', 'group' => 'JournalEntry'],
        ['name' => 'JournalEntryReverse', 'group' => 'JournalEntry'],
        /*
         * Posting into a frozen period — `docs/erpnext-gap-plan.md` Phase 3.
         *
         * `accounting.ledger_frozen_before` stops entries dated before it reaching the ledger, and this is
         * the exemption ERPNext carries on both of its equivalents. Without one, the first genuine
         * correction forces somebody to clear the date, post, and remember to set it back — a window that
         * is open silently. Granted to nobody below Administrator, and the activity log records the post.
         */
        ['name' => 'JournalEntryBackdate', 'group' => 'JournalEntry'],
        ['name' => 'FixedAssetView', 'group' => 'FixedAsset'],
        ['name' => 'FixedAssetCreate', 'group' => 'FixedAsset'],
        ['name' => 'FixedAssetUpdate', 'group' => 'FixedAsset'],
        ['name' => 'FixedAssetDelete', 'group' => 'FixedAsset'],
        ['name' => 'FixedAssetDepreciate', 'group' => 'FixedAsset'],
        ['name' => 'FixedAssetDispose', 'group' => 'FixedAsset'],
        ['name' => 'BankStatementView', 'group' => 'BankStatement'],
        ['name' => 'BankStatementCreate', 'group' => 'BankStatement'],
        ['name' => 'BankStatementUpdate', 'group' => 'BankStatement'],
        ['name' => 'BankStatementDelete', 'group' => 'BankStatement'],
        ['name' => 'BankStatementImport', 'group' => 'BankStatement'],
        ['name' => 'BankStatementMatch', 'group' => 'BankStatement'],
        ['name' => 'BankStatementComplete', 'group' => 'BankStatement'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // Records, does not approve.
        'Accountant' => [
            'AccountCreate',
            'AccountUpdate',
            'AccountView',
            'BankCreate',
            'BankStatementCreate',
            'BankStatementImport',
            'BankStatementMatch',
            'BankStatementUpdate',
            'BankStatementView',
            'BankUpdate',
            'BankView',
            'BeneficiaryCreate',
            'BeneficiaryUpdate',
            'BeneficiaryView',
            'BudgetCreate',
            'BudgetUpdate',
            'BudgetView',
            'CompanyBankAccountCreate',
            'CompanyBankAccountUpdate',
            'CompanyBankAccountView',
            'FixedAssetCreate',
            'FixedAssetUpdate',
            'FixedAssetView',
            'GnuCashImport',
            'JournalEntryCreate',
            'JournalEntrySubmit',
            'JournalEntryUpdate',
            'JournalEntryView',
            'LoanCreate',
            'LoanUpdate',
            'LoanView',
            'PaymentCreate',
            'PaymentDelete',
            'PaymentUpdate',
            'PaymentView',
            'PettyCashCreate',
            'PettyCashView',
            'RegisterPost',
            'ReportBuild',
            // Keeping a schedule of one's own, on the same reasoning as `ReportBuild`: anybody trusted to
            // read the ledger is trusted to have it emailed to them. Deleting is CEO's, with the other
            // deletes.
            'ReportScheduleCreate',
            'ReportScheduleUpdate',
            'ReportScheduleView',
            'ReportView',
            'TransactionTypeCreate',
            'TransactionTypeUpdate',
            'TransactionTypeView',
        ],
        // On top of Accountant.
        'Manager' => [
            'BankStatementComplete',
            'FixedAssetDepreciate',
            'FixedAssetDispose',
            'JournalEntryApprove',
            'JournalEntryPost',
            'JournalEntryReject',
            'JournalEntryReverse',
            'LoanRecord',
            'PettyCashReplenish',
        ],
        // On top of Manager.
        'CEO' => [
            'AccountDelete',
            'BankDelete',
            'BankStatementDelete',
            'BeneficiaryDelete',
            'BudgetDelete',
            'CompanyBankAccountDelete',
            'FixedAssetDelete',
            'LoanDelete',
            'ReportScheduleDelete',
            'TransactionTypeDelete',
        ],
    ],

    /**
     * Which domain of the two-level shell this module's screens appear in.
     *
     * Keyed on the navigation group label the resources and pages declare. Labels are shared —
     * "Employee" is claimed by ten modules — so agreement is normal and a label claimed for two
     * different domains throws in ModuleManifest rather than resolving to whichever manifest was
     * read last. The six domains themselves are App\Support\NavigationDomains.
     */
    'navigation' => [
        'Accounting' => 'finance',
        'Reports' => 'reports',
        'Settings' => 'admin',
    ],
];
