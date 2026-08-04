<?php

namespace App\Support;

use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Filament\Pages\BalanceSheet;
use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Accounting\Filament\Pages\CashFlow;
use App\Modules\Accounting\Filament\Pages\GnuCashImport;
use App\Modules\Accounting\Filament\Pages\PettyCashBook;
use App\Modules\Accounting\Filament\Pages\ProfitAndLoss;
use App\Modules\Accounting\Filament\Pages\TrialBalance;
use App\Modules\Accounting\Filament\Resources\Accounts\AccountResource;
use App\Modules\Accounting\Filament\Resources\Banks\BankResource;
use App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource;
use App\Modules\Accounting\Filament\Resources\BankStatements\BankStatementResource;
use App\Modules\Accounting\Filament\Resources\Beneficiaries\BeneficiaryResource;
use App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use App\Modules\Accounting\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use App\Modules\Accounting\Filament\Resources\Payments\PaymentResource;
use App\Modules\Accounting\Filament\Resources\TransactionTypes\TransactionTypeResource;
use App\Modules\Accounting\Filament\Widgets\AccountBalancesOverview;
use App\Modules\Accounting\Filament\Widgets\CashFlowChart;
use App\Modules\Accounting\Filament\Widgets\OperationsOverview;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bank;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Advances\Filament\Resources\Advances\AdvanceResource;
use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Models\AdvanceRecovery;
use App\Modules\Billing\Filament\Resources\BillingRuns\BillingRunResource;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Core\Filament\Pages\Auth\EditProfile;
use App\Modules\Core\Filament\Pages\CompanySettings;
use App\Modules\Core\Filament\Pages\Modules;
use App\Modules\Core\Filament\Pages\Tenancy\RegisterCompany;
use App\Modules\Core\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Modules\Core\Filament\Resources\Comments\CommentResource;
use App\Modules\Core\Filament\Resources\Companies\CompanyResource;
use App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource;
use App\Modules\Core\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Modules\Core\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Modules\Core\Filament\Resources\Permissions\PermissionResource;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\CustomFieldValue;
use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\TableView;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Inventory\Filament\Resources\Products\ProductResource;
use App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Invoicing\Filament\Pages\AgedPayables;
use App\Modules\Invoicing\Filament\Pages\AgedReceivables;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use App\Modules\Invoicing\Filament\Resources\InvoiceLines\InvoiceLineResource;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\ContactPerson;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Invoicing\Models\RecurringInvoiceLine;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Mpr\Filament\Resources\MPRs\MPRResource;
use App\Modules\Mpr\Models\MPR;
use App\Modules\Payroll\Filament\Pages\FbrTaxFile;
use App\Modules\Payroll\Filament\Pages\SalaryBankFile;
use App\Modules\Payroll\Filament\Pages\TaxSummary;
use App\Modules\Payroll\Filament\Resources\AnnualTaxes\AnnualTaxResource;
use App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource;
use App\Modules\Payroll\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\SalarySlabResource;
use App\Modules\Payroll\Filament\Widgets\PayrollByEmployeeChart;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponent;
use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Filament\Resources\Projects\Widgets\ProjectHealthChart;
use App\Modules\Projects\Filament\Widgets\CertificateExpiryTable;
use App\Modules\Projects\Filament\Widgets\EnvironmentHealthOverview;
use App\Modules\Projects\Filament\Widgets\EnvironmentIncidentsTable;
use App\Modules\Projects\Filament\Widgets\MyProjectsOverview;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentCheck;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
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
            'App\Models\EmailTemplate' => EmailTemplate::class,
            'App\Models\User' => User::class,
            'App\Models\Company' => Company::class,
            'App\Models\TableView' => TableView::class,
            'App\Models\CustomField' => CustomField::class,
            'App\Models\CustomFieldValue' => CustomFieldValue::class,
            'App\Models\ActivityLog' => ActivityLog::class,
            'App\Models\Comment' => Comment::class,
            'App\Models\FiscalYear' => FiscalYear::class,
            'App\Models\Setting' => Setting::class,
        ],
        'employees' => [
            'App\Models\Employee' => Employee::class,
            'App\Models\EmployeeChangeRequest' => EmployeeChangeRequest::class,
            'App\Models\EmployeeSetting' => EmployeeSetting::class,
        ],
        'advances' => [
            'App\Models\Advance' => Advance::class,
            'App\Models\AdvanceRecovery' => AdvanceRecovery::class,
        ],
        'expenses' => [
            'App\Models\ExpenseClaim' => ExpenseClaim::class,
        ],
        'payroll' => [
            'App\Models\PayrollRun' => PayrollRun::class,
            'App\Models\PayComponent' => PayComponent::class,
            'App\Models\EmployeeSettingComponent' => EmployeeSettingComponent::class,
            'App\Models\PayslipComponent' => PayslipComponent::class,
            'App\Models\Payslip' => Payslip::class,
            'App\Models\SalarySlab' => SalarySlab::class,
            'App\Models\AnnualTax' => AnnualTax::class,
        ],
        'accounting' => [
            'App\Models\Account' => Account::class,
            'App\Models\JournalEntry' => JournalEntry::class,
            'App\Models\JournalEntryLine' => JournalEntryLine::class,
            'App\Models\TransactionType' => TransactionType::class,
            'App\Models\Bank' => Bank::class,
            'App\Models\CompanyBankAccount' => CompanyBankAccount::class,
            'App\Models\Beneficiary' => Beneficiary::class,
            'App\Models\BeneficiarySubscription' => BeneficiarySubscription::class,
            'App\Models\Payment' => Payment::class,
            'App\Models\FixedAsset' => FixedAsset::class,
            'App\Models\BankStatement' => BankStatement::class,
            'App\Models\BankStatementLine' => BankStatementLine::class,
            'App\Models\PettyCashVoucher' => PettyCashVoucher::class,
        ],
        'invoicing' => [
            'App\Models\Contact' => Contact::class,
            'App\Models\Invoice' => Invoice::class,
            'App\Models\InvoiceLine' => InvoiceLine::class,
            'App\Models\TaxRate' => TaxRate::class,
            'App\Models\InvoiceEvent' => InvoiceEvent::class,
            'App\Models\ContactPerson' => ContactPerson::class,
            'App\Models\RecurringInvoice' => RecurringInvoice::class,
            'App\Models\RecurringInvoiceLine' => RecurringInvoiceLine::class,
        ],
        'billing' => [
            'App\Models\BillingRun' => BillingRun::class,
        ],
        'inventory' => [
            'App\Models\Product' => Product::class,
            'App\Models\StockMovement' => StockMovement::class,
        ],
        'projects' => [
            'App\Models\Project' => Project::class,
            'App\Models\ProjectEnvironment' => ProjectEnvironment::class,
            'App\Models\ProjectEnvironmentCheck' => ProjectEnvironmentCheck::class,
            'App\Models\ProjectEnvironmentIncident' => ProjectEnvironmentIncident::class,
        ],
        'mpr' => [
            'App\Models\MPR' => MPR::class,
        ],
    ];

    /**
     * Filament resources, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const RESOURCES = [
        'core' => [
            'App\Filament\Resources\EmailTemplates\EmailTemplateResource' => EmailTemplateResource::class,
            'App\Filament\Resources\Users\UserResource' => UserResource::class,
            'App\Filament\Resources\Roles\RoleResource' => RoleResource::class,
            'App\Filament\Resources\Permissions\PermissionResource' => PermissionResource::class,
            'App\Filament\Resources\Companies\CompanyResource' => CompanyResource::class,
            'App\Filament\Resources\CustomFields\CustomFieldResource' => CustomFieldResource::class,
            'App\Filament\Resources\ActivityLogs\ActivityLogResource' => ActivityLogResource::class,
            'App\Filament\Resources\Comments\CommentResource' => CommentResource::class,
            'App\Filament\Resources\FiscalYears\FiscalYearResource' => FiscalYearResource::class,
        ],
        'employees' => [
            'App\Filament\Resources\Employees\EmployeeResource' => EmployeeResource::class,
            'App\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource' => EmployeeChangeRequestResource::class,
            'App\Filament\Resources\EmployeeSettings\EmployeeSettingResource' => EmployeeSettingResource::class,
        ],
        'advances' => [
            'App\Filament\Resources\Advances\AdvanceResource' => AdvanceResource::class,
        ],
        'expenses' => [
            'App\Filament\Resources\ExpenseClaims\ExpenseClaimResource' => ExpenseClaimResource::class,
        ],
        'payroll' => [
            'App\Filament\Resources\PayComponents\PayComponentResource' => PayComponentResource::class,
            'App\Filament\Resources\PayrollRuns\PayrollRunResource' => PayrollRunResource::class,
            'App\Filament\Resources\Payslips\PayslipResource' => PayslipResource::class,
            'App\Filament\Resources\SalarySlabs\SalarySlabResource' => SalarySlabResource::class,
            'App\Filament\Resources\AnnualTaxes\AnnualTaxResource' => AnnualTaxResource::class,
        ],
        'accounting' => [
            'App\Filament\Resources\Accounts\AccountResource' => AccountResource::class,
            'App\Filament\Resources\JournalEntries\JournalEntryResource' => JournalEntryResource::class,
            'App\Filament\Resources\JournalEntryLines\JournalEntryLineResource' => JournalEntryLineResource::class,
            'App\Filament\Resources\TransactionTypes\TransactionTypeResource' => TransactionTypeResource::class,
            'App\Filament\Resources\Banks\BankResource' => BankResource::class,
            'App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource' => CompanyBankAccountResource::class,
            'App\Filament\Resources\Beneficiaries\BeneficiaryResource' => BeneficiaryResource::class,
            'App\Filament\Resources\Payments\PaymentResource' => PaymentResource::class,
            'App\Filament\Resources\FixedAssets\FixedAssetResource' => FixedAssetResource::class,
            'App\Filament\Resources\BankStatements\BankStatementResource' => BankStatementResource::class,
            'App\Filament\Resources\BankStatementLines\BankStatementLineResource' => BankStatementLineResource::class,
        ],
        'invoicing' => [
            'App\Filament\Resources\Contacts\ContactResource' => ContactResource::class,
            'App\Filament\Resources\Invoices\InvoiceResource' => InvoiceResource::class,
            'App\Filament\Resources\InvoiceLines\InvoiceLineResource' => InvoiceLineResource::class,
            'App\Filament\Resources\TaxRates\TaxRateResource' => TaxRateResource::class,
        ],
        'billing' => [
            'App\Filament\Resources\BillingRuns\BillingRunResource' => BillingRunResource::class,
        ],
        'inventory' => [
            'App\Filament\Resources\Products\ProductResource' => ProductResource::class,
            'App\Filament\Resources\StockMovements\StockMovementResource' => StockMovementResource::class,
        ],
        'projects' => [
            'App\Filament\Resources\Projects\ProjectResource' => ProjectResource::class,
        ],
        'mpr' => [
            'App\Filament\Resources\MPRs\MPRResource' => MPRResource::class,
        ],
    ];

    /**
     * Filament pages, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const PAGES = [
        'core' => [
            'App\Filament\Pages\CompanySettings' => CompanySettings::class,
            'App\Filament\Pages\Modules' => Modules::class,
            'App\Filament\Pages\Auth\EditProfile' => EditProfile::class,
            'App\Filament\Pages\Tenancy\RegisterCompany' => RegisterCompany::class,
        ],
        'payroll' => [
            'App\Filament\Pages\SalaryBankFile' => SalaryBankFile::class,
            'App\Filament\Pages\FbrTaxFile' => FbrTaxFile::class,
            'App\Filament\Pages\TaxSummary' => TaxSummary::class,
        ],
        'invoicing' => [
            'App\Filament\Pages\AgedReceivables' => AgedReceivables::class,
            'App\Filament\Pages\AgedPayables' => AgedPayables::class,
        ],
        'accounting' => [
            'App\Filament\Pages\AccountRegister' => AccountRegister::class,
            'App\Filament\Pages\CashFlow' => CashFlow::class,
            'App\Filament\Pages\BalanceSheet' => BalanceSheet::class,
            'App\Filament\Pages\TrialBalance' => TrialBalance::class,
            'App\Filament\Pages\ProfitAndLoss' => ProfitAndLoss::class,
            'App\Filament\Pages\GnuCashImport' => GnuCashImport::class,
            'App\Filament\Pages\PettyCashBook' => PettyCashBook::class,
            'App\Filament\Pages\BankPaymentFile' => BankPaymentFile::class,
        ],
    ];

    /**
     * Filament widgets, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const WIDGETS = [
        'payroll' => [
            'App\Filament\Widgets\PayrollByEmployeeChart' => PayrollByEmployeeChart::class,
        ],
        'accounting' => [
            'App\Filament\Widgets\AccountBalancesOverview' => AccountBalancesOverview::class,
            'App\Filament\Widgets\CashFlowChart' => CashFlowChart::class,
            'App\Filament\Widgets\OperationsOverview' => OperationsOverview::class,
        ],
        'projects' => [
            // Nested under the resource rather than in Filament/Widgets — found by
            // ModuleCoverageTest, which is exactly the kind of class a map written
            // by hand from a directory listing misses.
            'App\Filament\Resources\Projects\Widgets\ProjectHealthChart' => ProjectHealthChart::class,
            'App\Filament\Widgets\MyProjectsOverview' => MyProjectsOverview::class,
            'App\Filament\Widgets\EnvironmentHealthOverview' => EnvironmentHealthOverview::class,
            'App\Filament\Widgets\EnvironmentIncidentsTable' => EnvironmentIncidentsTable::class,
            'App\Filament\Widgets\CertificateExpiryTable' => CertificateExpiryTable::class,
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
            'FixedAsset', 'JournalEntry', 'Payment', 'TransactionType', 'PettyCash',
            'Report', 'Register', 'Import',
        ],
        'invoicing' => ['Invoicing'],
        'billing' => ['BillingRun'],
        'inventory' => ['Inventory'],
        'projects' => ['Project'],
        'mpr' => ['MPR'],
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
     * does not cover them — `where('source_type', Payslip::class)` would simply
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
