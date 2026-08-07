<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Which class belongs to which module.
 *
 * Kept in code rather than config/modules.php because it references classes, and
 * kept central rather than as a `$module` property on each class for three
 * reasons:
 *
 *  - the Modules page needs the whole mapping at once (record counts, what a
 *    module contains, which permission groups it owns);
 *  - a resource added without an entry fails ModuleCoverageTest rather than
 *    shipping ungated, which a forgotten property would not guarantee;
 *  - the physical move (phase 5) rewrites FQCNs here in one place, and the same
 *    list drives the morph map, so a moved model cannot be forgotten.
 *
 * MORPH MAP: the aliases in morphMap() are the *legacy* `App\Models\…` strings,
 * because those are what is already stored in customer data — `comments.commentable_type`,
 * `payments.payable_type`, `activity_log.subject_type`, `custom_fields.model_type`,
 * `model_has_roles.model_type` and `table_views.resource`. Keeping the alias
 * fixed while the target class moves means existing rows keep resolving and new
 * rows are written identically, with no per-tenant data migration. Ugly strings,
 * correct behaviour.
 */
final class ModuleMap
{
    /**
     * Models, keyed by module. The array key is the legacy `App\Models\…` string
     * used as the morph alias; the value is the class it resolves to today.
     *
     * Phase 5 changes only the values.
     *
     * @var array<string, array<string, class-string>>
     */
    private const MODELS = [
        'core' => [
            'App\Models\EmailTemplate' => \App\Modules\Core\Models\EmailTemplate::class,
            'App\Models\User' => \App\Modules\Core\Models\User::class,
            'App\Models\Company' => \App\Modules\Core\Models\Company::class,
            'App\Models\TableView' => \App\Modules\Core\Models\TableView::class,
            'App\Models\CustomField' => \App\Modules\Core\Models\CustomField::class,
            'App\Models\CustomFieldValue' => \App\Modules\Core\Models\CustomFieldValue::class,
            'App\Models\ActivityLog' => \App\Modules\Core\Models\ActivityLog::class,
            'App\Models\Comment' => \App\Modules\Core\Models\Comment::class,
            'App\Models\FiscalYear' => \App\Modules\Core\Models\FiscalYear::class,
            'App\Models\Setting' => \App\Modules\Core\Models\Setting::class,
        ],
        'employees' => [
            'App\Models\Employee' => \App\Modules\Employees\Models\Employee::class,
            'App\Models\EmployeeChangeRequest' => \App\Modules\Employees\Models\EmployeeChangeRequest::class,
            'App\Models\EmployeeSetting' => \App\Modules\Employees\Models\EmployeeSetting::class,
        ],
        'advances' => [
            'App\Models\Advance' => \App\Modules\Advances\Models\Advance::class,
            'App\Models\AdvanceRecovery' => \App\Modules\Advances\Models\AdvanceRecovery::class,
        ],
        'expenses' => [
            'App\Models\ExpenseClaim' => \App\Modules\Expenses\Models\ExpenseClaim::class,
        ],
        'payroll' => [
            'App\Models\PayrollRun' => \App\Modules\Payroll\Models\PayrollRun::class,
            'App\Models\PayComponent' => \App\Modules\Payroll\Models\PayComponent::class,
            'App\Models\EmployeeSettingComponent' => \App\Modules\Payroll\Models\EmployeeSettingComponent::class,
            'App\Models\PayslipComponent' => \App\Modules\Payroll\Models\PayslipComponent::class,
            'App\Models\Payslip' => \App\Modules\Payroll\Models\Payslip::class,
            'App\Models\SalarySlab' => \App\Modules\Payroll\Models\SalarySlab::class,
            'App\Models\AnnualTax' => \App\Modules\Payroll\Models\AnnualTax::class,
        ],
        'accounting' => [
            'App\Models\Account' => \App\Modules\Accounting\Models\Account::class,
            'App\Models\Currency' => \App\Modules\Accounting\Models\Currency::class,
            'App\Models\ExchangeRate' => \App\Modules\Accounting\Models\ExchangeRate::class,
            'App\Models\JournalEntry' => \App\Modules\Accounting\Models\JournalEntry::class,
            'App\Models\JournalEntryLine' => \App\Modules\Accounting\Models\JournalEntryLine::class,
            'App\Models\TransactionType' => \App\Modules\Accounting\Models\TransactionType::class,
            'App\Models\Bank' => \App\Modules\Accounting\Models\Bank::class,
            'App\Models\CompanyBankAccount' => \App\Modules\Accounting\Models\CompanyBankAccount::class,
            'App\Models\Beneficiary' => \App\Modules\Accounting\Models\Beneficiary::class,
            'App\Models\Budget' => \App\Modules\Accounting\Models\Budget::class,
            'App\Models\BudgetLine' => \App\Modules\Accounting\Models\BudgetLine::class,
            'App\Models\BeneficiarySubscription' => \App\Modules\Accounting\Models\BeneficiarySubscription::class,
            'App\Models\Payment' => \App\Modules\Accounting\Models\Payment::class,
            'App\Models\FixedAsset' => \App\Modules\Accounting\Models\FixedAsset::class,
            'App\Models\BankStatement' => \App\Modules\Accounting\Models\BankStatement::class,
            'App\Models\BankStatementLine' => \App\Modules\Accounting\Models\BankStatementLine::class,
            'App\Models\PettyCashVoucher' => \App\Modules\Accounting\Models\PettyCashVoucher::class,
            'App\Models\ScheduledTransaction' => \App\Modules\Accounting\Models\ScheduledTransaction::class,
            'App\Models\ScheduledTransactionLine' => \App\Modules\Accounting\Models\ScheduledTransactionLine::class,
        ],
        'invoicing' => [
            'App\Models\Contact' => \App\Modules\Invoicing\Models\Contact::class,
            'App\Models\Invoice' => \App\Modules\Invoicing\Models\Invoice::class,
            'App\Models\InvoiceLine' => \App\Modules\Invoicing\Models\InvoiceLine::class,
            'App\Models\TaxRate' => \App\Modules\Invoicing\Models\TaxRate::class,
            'App\Models\InvoiceEvent' => \App\Modules\Invoicing\Models\InvoiceEvent::class,
            'App\Models\ContactPerson' => \App\Modules\Invoicing\Models\ContactPerson::class,
            'App\Models\RecurringInvoice' => \App\Modules\Invoicing\Models\RecurringInvoice::class,
            'App\Models\RecurringInvoiceLine' => \App\Modules\Invoicing\Models\RecurringInvoiceLine::class,
        ],
        'billing' => [
            'App\Models\BillingRun' => \App\Modules\Billing\Models\BillingRun::class,
        ],
        'inventory' => [
            'App\Models\Product' => \App\Modules\Inventory\Models\Product::class,
            'App\Models\StockMovement' => \App\Modules\Inventory\Models\StockMovement::class,
        ],
        'projects' => [
            'App\Models\Project' => \App\Modules\Projects\Models\Project::class,
            'App\Models\ProjectEnvironment' => \App\Modules\Projects\Models\ProjectEnvironment::class,
            'App\Models\ProjectEnvironmentCheck' => \App\Modules\Projects\Models\ProjectEnvironmentCheck::class,
            'App\Models\ProjectEnvironmentIncident' => \App\Modules\Projects\Models\ProjectEnvironmentIncident::class,
        ],
        'mpr' => [
            'App\Models\MPR' => \App\Modules\Mpr\Models\MPR::class,
        ],
        'personal_finance' => [
            'App\Models\PersonalTaxProfile' => \App\Modules\PersonalFinance\Models\PersonalTaxProfile::class,
            'App\Models\TaxSchedule' => \App\Modules\PersonalFinance\Models\TaxSchedule::class,
            'App\Models\TaxSurcharge' => \App\Modules\PersonalFinance\Models\TaxSurcharge::class,
        ],
    ];

    /**
     * Filament resources, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const RESOURCES = [
        'core' => [
            'App\Filament\Resources\EmailTemplates\EmailTemplateResource' => \App\Modules\Core\Filament\Resources\EmailTemplates\EmailTemplateResource::class,
            'App\Filament\Resources\Users\UserResource' => \App\Modules\Core\Filament\Resources\Users\UserResource::class,
            'App\Filament\Resources\Roles\RoleResource' => \App\Modules\Core\Filament\Resources\Roles\RoleResource::class,
            'App\Filament\Resources\Permissions\PermissionResource' => \App\Modules\Core\Filament\Platform\Resources\Permissions\PermissionResource::class,
            'App\Filament\Platform\Resources\Roles\PlatformRoleResource' => \App\Modules\Core\Filament\Platform\Resources\Roles\PlatformRoleResource::class,
            'App\Filament\Platform\Resources\Users\PlatformUserResource' => \App\Modules\Core\Filament\Platform\Resources\Users\PlatformUserResource::class,
            'App\Filament\Platform\Resources\ActivityLogs\PlatformActivityLogResource' => \App\Modules\Core\Filament\Platform\Resources\ActivityLogs\PlatformActivityLogResource::class,
            'App\Filament\Resources\Companies\CompanyResource' => \App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource::class,
            'App\Filament\Resources\CustomFields\CustomFieldResource' => \App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource::class,
            'App\Filament\Resources\ActivityLogs\ActivityLogResource' => \App\Modules\Core\Filament\Resources\ActivityLogs\ActivityLogResource::class,
            'App\Filament\Resources\Comments\CommentResource' => \App\Modules\Core\Filament\Resources\Comments\CommentResource::class,
            'App\Filament\Resources\FiscalYears\FiscalYearResource' => \App\Modules\Core\Filament\Resources\FiscalYears\FiscalYearResource::class,
        ],
        'employees' => [
            'App\Filament\Resources\Employees\EmployeeResource' => \App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::class,
            'App\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource' => \App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource::class,
            'App\Filament\Resources\EmployeeSettings\EmployeeSettingResource' => \App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource::class,
        ],
        'advances' => [
            'App\Filament\Resources\Advances\AdvanceResource' => \App\Modules\Advances\Filament\Resources\Advances\AdvanceResource::class,
        ],
        'expenses' => [
            'App\Filament\Resources\ExpenseClaims\ExpenseClaimResource' => \App\Modules\Expenses\Filament\Resources\ExpenseClaims\ExpenseClaimResource::class,
        ],
        'payroll' => [
            'App\Filament\Resources\PayComponents\PayComponentResource' => \App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource::class,
            'App\Filament\Resources\PayrollRuns\PayrollRunResource' => \App\Modules\Payroll\Filament\Resources\PayrollRuns\PayrollRunResource::class,
            'App\Filament\Resources\Payslips\PayslipResource' => \App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource::class,
            'App\Filament\Resources\SalarySlabs\SalarySlabResource' => \App\Modules\Payroll\Filament\Resources\SalarySlabs\SalarySlabResource::class,
            'App\Filament\Resources\AnnualTaxes\AnnualTaxResource' => \App\Modules\Payroll\Filament\Resources\AnnualTaxes\AnnualTaxResource::class,
        ],
        'accounting' => [
            'App\Filament\Resources\Accounts\AccountResource' => \App\Modules\Accounting\Filament\Resources\Accounts\AccountResource::class,
            'App\Filament\Resources\Currencies\CurrencyResource' => \App\Modules\Accounting\Filament\Resources\Currencies\CurrencyResource::class,
            'App\Filament\Resources\JournalEntries\JournalEntryResource' => \App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource::class,
            'App\Filament\Resources\JournalEntryLines\JournalEntryLineResource' => \App\Modules\Accounting\Filament\Resources\JournalEntryLines\JournalEntryLineResource::class,
            'App\Filament\Resources\TransactionTypes\TransactionTypeResource' => \App\Modules\Accounting\Filament\Resources\TransactionTypes\TransactionTypeResource::class,
            'App\Filament\Resources\Banks\BankResource' => \App\Modules\Accounting\Filament\Resources\Banks\BankResource::class,
            'App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource' => \App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource::class,
            'App\Filament\Resources\Beneficiaries\BeneficiaryResource' => \App\Modules\Accounting\Filament\Resources\Beneficiaries\BeneficiaryResource::class,
            'App\Filament\Resources\Budgets\BudgetResource' => \App\Modules\Accounting\Filament\Resources\Budgets\BudgetResource::class,
            'App\Filament\Resources\Payments\PaymentResource' => \App\Modules\Accounting\Filament\Resources\Payments\PaymentResource::class,
            'App\Filament\Resources\FixedAssets\FixedAssetResource' => \App\Modules\Accounting\Filament\Resources\FixedAssets\FixedAssetResource::class,
            'App\Filament\Resources\BankStatements\BankStatementResource' => \App\Modules\Accounting\Filament\Resources\BankStatements\BankStatementResource::class,
            'App\Filament\Resources\BankStatementLines\BankStatementLineResource' => \App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource::class,
            'App\Filament\Resources\ScheduledTransactions\ScheduledTransactionResource' => \App\Modules\Accounting\Filament\Resources\ScheduledTransactions\ScheduledTransactionResource::class,
        ],
        'invoicing' => [
            'App\Filament\Resources\Contacts\ContactResource' => \App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource::class,
            'App\Filament\Resources\Invoices\InvoiceResource' => \App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource::class,
            'App\Filament\Resources\InvoiceLines\InvoiceLineResource' => \App\Modules\Invoicing\Filament\Resources\InvoiceLines\InvoiceLineResource::class,
            'App\Filament\Resources\TaxRates\TaxRateResource' => \App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource::class,
        ],
        'billing' => [
            'App\Filament\Resources\BillingRuns\BillingRunResource' => \App\Modules\Billing\Filament\Resources\BillingRuns\BillingRunResource::class,
        ],
        'inventory' => [
            'App\Filament\Resources\Products\ProductResource' => \App\Modules\Inventory\Filament\Resources\Products\ProductResource::class,
            'App\Filament\Resources\StockMovements\StockMovementResource' => \App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource::class,
        ],
        'projects' => [
            'App\Filament\Resources\Projects\ProjectResource' => \App\Modules\Projects\Filament\Resources\Projects\ProjectResource::class,
        ],
        'mpr' => [
            'App\Filament\Resources\MPRs\MPRResource' => \App\Modules\Mpr\Filament\Resources\MPRs\MPRResource::class,
        ],
    ];

    /**
     * Filament pages, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const PAGES = [
        'core' => [
            // The hub the report pages are reached through. Core because it spans
            // four modules; each link is gated by the page behind it.
            'App\Filament\Pages\Reports' => \App\Modules\Core\Filament\Pages\Reports::class,
            // The cross-module walkthroughs. Core because a manual that
            // disappears with a module cannot explain the module.
            'App\Filament\Pages\UserManual' => \App\Modules\Core\Filament\Pages\UserManual::class,
            'App\Filament\Pages\CompanySettings' => \App\Modules\Core\Filament\Pages\CompanySettings::class,
            'App\Filament\Pages\Modules' => \App\Modules\Core\Filament\Pages\Modules::class,
            'App\Filament\Pages\CsvImport' => \App\Modules\Core\Filament\Pages\CsvImport::class,
            'App\Filament\Pages\Auth\EditProfile' => \App\Modules\Core\Filament\Pages\Auth\EditProfile::class,
        ],
        'payroll' => [
            'App\Filament\Pages\SalaryBankFile' => \App\Modules\Payroll\Filament\Pages\SalaryBankFile::class,
            'App\Filament\Pages\FbrTaxFile' => \App\Modules\Payroll\Filament\Pages\FbrTaxFile::class,
            'App\Filament\Pages\TaxSummary' => \App\Modules\Payroll\Filament\Pages\TaxSummary::class,
        ],
        'invoicing' => [
            'App\Filament\Pages\AgedReceivables' => \App\Modules\Invoicing\Filament\Pages\AgedReceivables::class,
            'App\Filament\Pages\AgedPayables' => \App\Modules\Invoicing\Filament\Pages\AgedPayables::class,
        ],
        'personal_finance' => [
            'App\Filament\Pages\TaxEstimate' => \App\Modules\PersonalFinance\Filament\Pages\TaxEstimate::class,
        ],
        'accounting' => [
            'App\Filament\Pages\AccountRegister' => \App\Modules\Accounting\Filament\Pages\AccountRegister::class,
            'App\Filament\Pages\FindTransactions' => \App\Modules\Accounting\Filament\Pages\FindTransactions::class,
            'App\Filament\Pages\CashFlow' => \App\Modules\Accounting\Filament\Pages\CashFlow::class,
            'App\Filament\Pages\ContractorPayments' => \App\Modules\Accounting\Filament\Pages\ContractorPayments::class,
            'App\Filament\Pages\CurrencyRevaluation' => \App\Modules\Accounting\Filament\Pages\CurrencyRevaluation::class,
            'App\Filament\Pages\BalanceSheet' => \App\Modules\Accounting\Filament\Pages\BalanceSheet::class,
            'App\Filament\Pages\BudgetVsActual' => \App\Modules\Accounting\Filament\Pages\BudgetVsActual::class,
            'App\Filament\Pages\TrialBalance' => \App\Modules\Accounting\Filament\Pages\TrialBalance::class,
            'App\Filament\Pages\ProfitAndLoss' => \App\Modules\Accounting\Filament\Pages\ProfitAndLoss::class,
            'App\Filament\Pages\GnuCashImport' => \App\Modules\Accounting\Filament\Pages\GnuCashImport::class,
            'App\Filament\Pages\PettyCashBook' => \App\Modules\Accounting\Filament\Pages\PettyCashBook::class,
            'App\Filament\Pages\BankPaymentFile' => \App\Modules\Accounting\Filament\Pages\BankPaymentFile::class,
        ],
    ];

    /**
     * Filament widgets, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const WIDGETS = [
        'payroll' => [
            'App\Filament\Widgets\PayrollByEmployeeChart' => \App\Modules\Payroll\Filament\Widgets\PayrollByEmployeeChart::class,
        ],
        'accounting' => [
            'App\Filament\Widgets\AccountBalancesOverview' => \App\Modules\Accounting\Filament\Widgets\AccountBalancesOverview::class,
            'App\Filament\Widgets\CashFlowChart' => \App\Modules\Accounting\Filament\Widgets\CashFlowChart::class,
            'App\Filament\Widgets\OperationsOverview' => \App\Modules\Accounting\Filament\Widgets\OperationsOverview::class,
        ],
        'invoicing' => [
            'App\Filament\Widgets\ReceivablesPayablesOverview' => \App\Modules\Invoicing\Filament\Widgets\ReceivablesPayablesOverview::class,
        ],
        'projects' => [
            // Nested under the resource rather than in Filament/Widgets — found by
            // ModuleCoverageTest, which is exactly the kind of class a map written
            // by hand from a directory listing misses.
            'App\Filament\Resources\Projects\Widgets\ProjectHealthChart' => \App\Modules\Projects\Filament\Resources\Projects\Widgets\ProjectHealthChart::class,
            'App\Filament\Widgets\MyProjectsOverview' => \App\Modules\Projects\Filament\Widgets\MyProjectsOverview::class,
            'App\Filament\Widgets\EnvironmentHealthOverview' => \App\Modules\Projects\Filament\Widgets\EnvironmentHealthOverview::class,
            'App\Filament\Widgets\EnvironmentIncidentsTable' => \App\Modules\Projects\Filament\Widgets\EnvironmentIncidentsTable::class,
            'App\Filament\Widgets\CertificateExpiryTable' => \App\Modules\Projects\Filament\Widgets\CertificateExpiryTable::class,
        ],
    ];

    /**
     * Permission `group` values owned by each module (see PermissionSeeder).
     *
     * Not derived from the model names: several groups are named for a feature
     * rather than a model — Report and Register are the reporting pages, Import
     * is the GnuCash importer, Invoicing covers Contact and Invoice together.
     *
     * @var array<string, array<int, string>>
     */
    private const PERMISSION_GROUPS = [
        'core' => ['User', 'Role', 'Permission', 'ActivityLog', 'Comment', 'FiscalYear'],
        'employees' => ['Employee', 'EmployeeSetting'],
        'advances' => ['Advance'],
        'expenses' => ['ExpenseClaim'],
        'payroll' => ['Payslip', 'SalarySlab', 'AnnualTax'],
        'accounting' => [
            'Account', 'Bank', 'BankStatement', 'Beneficiary', 'CompanyBankAccount',
            'Budget', 'FixedAsset', 'JournalEntry', 'Payment', 'TransactionType', 'PettyCash',
            'Report', 'Register', 'Import',
        ],
        'invoicing' => ['Invoicing'],
        'billing' => ['BillingRun'],
        'inventory' => ['Inventory'],
        'projects' => ['Project'],
        'mpr' => ['MPR'],
        'personal_finance' => ['PersonalFinance'],
    ];

    /**
     * Legacy FQCN => current class, for Relation::enforceMorphMap().
     *
     * @return array<string, class-string>
     */
    public static function morphMap(): array
    {
        return array_merge(...array_values(self::MODELS));
    }

    /**
     * The stable string to store for a class, rather than the class itself.
     *
     * Anywhere a class name is written into a column, this is what goes in:
     * `journal_entries.source_type`, `stock_movements.source_type`,
     * `payments.payable_type`, `custom_fields.model_type`, `table_views.resource`.
     *
     * Those are plain column writes, not morph relations, so enforceMorphMap()
     * does not cover them — `where('source_type', \App\Modules\Payroll\Models\Payslip::class)` would simply
     * stop matching the day Payslip moves into app/Modules/Payroll, and
     * unwindForPayslip would quietly find no entries to reverse. Storing the
     * alias instead keeps every existing row valid across the move.
     *
     * Unmapped classes return unchanged, so this is safe to wrap around anything.
     */
    public static function alias(string $class): string
    {
        foreach ([self::MODELS, self::RESOURCES, self::PAGES, self::WIDGETS] as $table) {
            foreach ($table as $classes) {
                $alias = array_search($class, $classes, true);

                if ($alias !== false) {
                    return $alias;
                }
            }
        }

        return $class;
    }

    /**
     * The module a class belongs to, or null when it is not mapped.
     *
     * Namespace derivation comes first so that once a class lives in
     * app/Modules/Payroll/… it is gated by where it sits, and the explicit
     * tables below are only needed until phase 5 has moved it.
     */
    public static function moduleFor(string $class): ?string
    {
        if (preg_match('/^App\\\\Modules\\\\([A-Za-z0-9]+)\\\\/', $class, $matches)) {
            $module = Str::snake($matches[1]);

            return array_key_exists($module, config('modules', [])) ? $module : null;
        }

        foreach ([self::RESOURCES, self::PAGES, self::WIDGETS] as $table) {
            foreach ($table as $module => $classes) {
                if (in_array($class, $classes, true)) {
                    return $module;
                }
            }
        }

        foreach (self::MODELS as $module => $models) {
            if (in_array($class, $models, true)) {
                return $module;
            }
        }

        return null;
    }

    /** @return array<int, class-string> */
    public static function resources(?string $module = null): array
    {
        return self::flatten(self::RESOURCES, $module);
    }

    /** @return array<int, class-string> */
    public static function pages(?string $module = null): array
    {
        return self::flatten(self::PAGES, $module);
    }

    /** @return array<int, class-string> */
    public static function widgets(?string $module = null): array
    {
        return self::flatten(self::WIDGETS, $module);
    }

    /** @return array<int, class-string> */
    public static function models(?string $module = null): array
    {
        if ($module !== null) {
            return array_values(self::MODELS[$module] ?? []);
        }

        return array_values(self::morphMap());
    }

    /** @return array<int, string> */
    public static function permissionGroups(?string $module = null): array
    {
        return self::flatten(self::PERMISSION_GROUPS, $module);
    }

    /**
     * The module owning a permission group, or null for a group no module claims
     * (which PermissionCoverageTest treats as a failure, not a default).
     */
    public static function moduleForPermissionGroup(string $group): ?string
    {
        foreach (self::PERMISSION_GROUPS as $module => $groups) {
            if (in_array($group, $groups, true)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<int, mixed>>  $table
     * @return array<int, mixed>
     */
    private static function flatten(array $table, ?string $module): array
    {
        if ($module !== null) {
            return array_values($table[$module] ?? []);
        }

        return array_values(array_merge(...array_values($table)));
    }
}
