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
            'App\Models\User' => \App\Models\User::class,
            'App\Models\Company' => \App\Models\Company::class,
            'App\Models\TableView' => \App\Models\TableView::class,
            'App\Models\CustomField' => \App\Models\CustomField::class,
            'App\Models\CustomFieldValue' => \App\Models\CustomFieldValue::class,
            'App\Models\ActivityLog' => \App\Models\ActivityLog::class,
            'App\Models\Comment' => \App\Models\Comment::class,
            'App\Models\FiscalYear' => \App\Models\FiscalYear::class,
            'App\Models\Setting' => \App\Models\Setting::class,
        ],
        'employees' => [
            'App\Models\Employee' => \App\Models\Employee::class,
            'App\Models\EmployeeChangeRequest' => \App\Models\EmployeeChangeRequest::class,
            'App\Models\EmployeeSetting' => \App\Models\EmployeeSetting::class,
        ],
        'payroll' => [
            'App\Models\Payslip' => \App\Models\Payslip::class,
            'App\Models\SalarySlab' => \App\Models\SalarySlab::class,
            'App\Models\AnnualTax' => \App\Models\AnnualTax::class,
        ],
        'accounting' => [
            'App\Models\Account' => \App\Models\Account::class,
            'App\Models\JournalEntry' => \App\Models\JournalEntry::class,
            'App\Models\JournalEntryLine' => \App\Models\JournalEntryLine::class,
            'App\Models\TransactionType' => \App\Models\TransactionType::class,
            'App\Models\Bank' => \App\Models\Bank::class,
            'App\Models\CompanyBankAccount' => \App\Models\CompanyBankAccount::class,
            'App\Models\Beneficiary' => \App\Models\Beneficiary::class,
            'App\Models\Payment' => \App\Models\Payment::class,
            'App\Models\FixedAsset' => \App\Models\FixedAsset::class,
            'App\Models\BankStatement' => \App\Models\BankStatement::class,
            'App\Models\BankStatementLine' => \App\Models\BankStatementLine::class,
            'App\Models\PettyCashVoucher' => \App\Models\PettyCashVoucher::class,
        ],
        'invoicing' => [
            'App\Models\Contact' => \App\Models\Contact::class,
            'App\Models\Invoice' => \App\Models\Invoice::class,
            'App\Models\InvoiceLine' => \App\Models\InvoiceLine::class,
        ],
        'inventory' => [
            'App\Models\Product' => \App\Models\Product::class,
            'App\Models\StockMovement' => \App\Models\StockMovement::class,
        ],
        'projects' => [
            'App\Models\Project' => \App\Models\Project::class,
            'App\Models\ProjectEnvironment' => \App\Models\ProjectEnvironment::class,
            'App\Models\ProjectEnvironmentCheck' => \App\Models\ProjectEnvironmentCheck::class,
            'App\Models\ProjectEnvironmentIncident' => \App\Models\ProjectEnvironmentIncident::class,
        ],
        'mpr' => [
            'App\Models\MPR' => \App\Models\MPR::class,
        ],
    ];

    /**
     * Filament resources, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const RESOURCES = [
        'core' => [
            \App\Filament\Resources\Users\UserResource::class,
            \App\Filament\Resources\Roles\RoleResource::class,
            \App\Filament\Resources\Permissions\PermissionResource::class,
            \App\Filament\Resources\Companies\CompanyResource::class,
            \App\Filament\Resources\TableViews\TableViewResource::class,
            \App\Filament\Resources\CustomFields\CustomFieldResource::class,
            \App\Filament\Resources\ActivityLogs\ActivityLogResource::class,
            \App\Filament\Resources\Comments\CommentResource::class,
            \App\Filament\Resources\FiscalYears\FiscalYearResource::class,
        ],
        'employees' => [
            \App\Filament\Resources\Employees\EmployeeResource::class,
            \App\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource::class,
            \App\Filament\Resources\EmployeeSettings\EmployeeSettingResource::class,
        ],
        'payroll' => [
            \App\Filament\Resources\Payslips\PayslipResource::class,
            \App\Filament\Resources\SalarySlabs\SalarySlabResource::class,
            \App\Filament\Resources\AnnualTaxes\AnnualTaxResource::class,
        ],
        'accounting' => [
            \App\Filament\Resources\Accounts\AccountResource::class,
            \App\Filament\Resources\JournalEntries\JournalEntryResource::class,
            \App\Filament\Resources\JournalEntryLines\JournalEntryLineResource::class,
            \App\Filament\Resources\TransactionTypes\TransactionTypeResource::class,
            \App\Filament\Resources\Banks\BankResource::class,
            \App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource::class,
            \App\Filament\Resources\Beneficiaries\BeneficiaryResource::class,
            \App\Filament\Resources\Payments\PaymentResource::class,
            \App\Filament\Resources\FixedAssets\FixedAssetResource::class,
            \App\Filament\Resources\BankStatements\BankStatementResource::class,
            \App\Filament\Resources\BankStatementLines\BankStatementLineResource::class,
        ],
        'invoicing' => [
            \App\Filament\Resources\Contacts\ContactResource::class,
            \App\Filament\Resources\Invoices\InvoiceResource::class,
            \App\Filament\Resources\InvoiceLines\InvoiceLineResource::class,
        ],
        'inventory' => [
            \App\Filament\Resources\Products\ProductResource::class,
            \App\Filament\Resources\StockMovements\StockMovementResource::class,
        ],
        'projects' => [
            \App\Filament\Resources\Projects\ProjectResource::class,
        ],
        'mpr' => [
            \App\Filament\Resources\MPRs\MPRResource::class,
        ],
    ];

    /**
     * Filament pages, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const PAGES = [
        'core' => [
            \App\Filament\Pages\CompanySettings::class,
            \App\Filament\Pages\Auth\EditProfile::class,
            \App\Filament\Pages\Tenancy\RegisterCompany::class,
        ],
        'payroll' => [
            \App\Filament\Pages\SalaryBankFile::class,
            \App\Filament\Pages\FbrTaxFile::class,
        ],
        'accounting' => [
            \App\Filament\Pages\AccountRegister::class,
            \App\Filament\Pages\TrialBalance::class,
            \App\Filament\Pages\ProfitAndLoss::class,
            \App\Filament\Pages\GnuCashImport::class,
            \App\Filament\Pages\PettyCashBook::class,
            \App\Filament\Pages\BankPaymentFile::class,
        ],
    ];

    /**
     * Filament widgets, keyed by module.
     *
     * @var array<string, array<int, class-string>>
     */
    private const WIDGETS = [
        'payroll' => [
            \App\Filament\Widgets\PayrollByEmployeeChart::class,
        ],
        'accounting' => [
            \App\Filament\Widgets\AccountBalancesOverview::class,
            \App\Filament\Widgets\CashFlowChart::class,
            \App\Filament\Widgets\OperationsOverview::class,
        ],
        'projects' => [
            // Nested under the resource rather than in Filament/Widgets — found by
            // ModuleCoverageTest, which is exactly the kind of class a map written
            // by hand from a directory listing misses.
            \App\Filament\Resources\Projects\Widgets\ProjectHealthChart::class,
            \App\Filament\Widgets\MyProjectsOverview::class,
            \App\Filament\Widgets\EnvironmentHealthOverview::class,
            \App\Filament\Widgets\EnvironmentIncidentsTable::class,
            \App\Filament\Widgets\CertificateExpiryTable::class,
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
        'payroll' => ['Payslip', 'SalarySlab', 'AnnualTax'],
        'accounting' => [
            'Account', 'Bank', 'BankStatement', 'Beneficiary', 'CompanyBankAccount',
            'FixedAsset', 'JournalEntry', 'Payment', 'TransactionType', 'PettyCash',
            'Report', 'Register', 'Import',
        ],
        'invoicing' => ['Invoicing'],
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
