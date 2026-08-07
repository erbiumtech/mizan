<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\BudgetVsActual;
use App\Modules\Accounting\Filament\Resources\Budgets\BudgetResource;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Core\Models\FiscalYear;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Who may plan, and who may only read the plan.
 *
 * A budget is not the ledger: it says what the company intends to do, which is a
 * thing somebody can reasonably be allowed to keep the books without being shown.
 * So it has its own permissions rather than riding on ReportView, and this is
 * where that separation is held.
 */
class BudgetAccessTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private function budget(): Budget
    {
        return Budget::create([
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'name' => 'Plan',
        ]);
    }

    private function signIn(string $role): void
    {
        $this->actingAs($this->makeUser($role, strtolower($role).'@budget.test'));
        $this->setCurrentTenant();
    }

    public function test_an_accountant_may_plan_but_not_delete(): void
    {
        $this->signIn('Accountant');
        $budget = $this->budget();

        $this->assertTrue(BudgetResource::canViewAny());
        $this->assertTrue(BudgetResource::canCreate());
        $this->assertTrue(BudgetResource::canEdit($budget));
        $this->assertFalse(BudgetResource::canDelete($budget), 'A plan reported against is evidence of what was agreed.');
    }

    public function test_a_ceo_may_delete(): void
    {
        $this->signIn('CEO');

        $this->assertTrue(BudgetResource::canDelete($this->budget()));
    }

    public function test_an_employee_has_no_access_at_all(): void
    {
        $this->signIn('Employee');

        $this->assertFalse(BudgetResource::canViewAny());
        $this->assertFalse(BudgetVsActual::canAccess());
    }

    public function test_the_report_is_gated_on_budget_view_not_report_view(): void
    {
        // The separation the permission exists for. An Employee holds neither, so
        // it proves nothing; the Accountant holds both. Asserted by taking
        // BudgetView away from somebody who still has ReportView.
        $this->signIn('Accountant');

        $this->assertTrue(BudgetVsActual::canAccess());

        auth()->user()->roles->first()->revokePermissionTo('BudgetView');
        auth()->user()->unsetRelation('roles')->unsetRelation('permissions');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(auth()->user()->can('ReportView'), 'Still allowed to read the accounts…');
        $this->assertFalse(BudgetVsActual::canAccess(), '…but no longer shown what was planned.');
    }

    public function test_a_closed_year_freezes_its_budget(): void
    {
        $this->signIn('Administrator');
        $budget = $this->budget();

        // Administrator passes Gate::before for everything except create, so the
        // policy is asked directly — otherwise this test would pass on the bypass
        // rather than on the rule.
        $this->assertTrue(app(\App\Modules\Accounting\Policies\BudgetPolicy::class)->update(auth()->user(), $budget));

        FiscalYear::whereKey($this->fiscalYear->getKey())->update(['closed_at' => now()]);

        $this->assertFalse(
            app(\App\Modules\Accounting\Policies\BudgetPolicy::class)->update(auth()->user(), $budget->fresh()),
            'Editing a closed year\'s plan changes what that year is measured against.',
        );
    }
}
