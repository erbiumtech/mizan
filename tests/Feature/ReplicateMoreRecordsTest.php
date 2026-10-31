<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\Core\Filament\Resources\Holidays\Pages\ListHolidays;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\Holiday;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Work\Filament\Resources\WorkWorkflows\Pages\EditWorkWorkflow;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Models\WorkWorkflowRule;
use App\Support\Replication\RecordReplicator;
use Database\Seeders\WorkWorkflowSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The second round of Replicate — docs/replicate-records.md, "Decisions left open", now decided.
 *
 * Each test proves the part of a copy that would hurt if it were wrong: a workflow whose rules still
 * point at the original's statuses, a budget that becomes current before anyone approved it, a claim
 * that reaches an approver or the ledger, an asset that starts half depreciated, a holiday that collides
 * with one already on the calendar. And the sheet, which stands in for a record's page, offers Replicate
 * as that page does.
 */
class ReplicateMoreRecordsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'replicate-more@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['projects', 'work', 'construction', 'construction_costing', 'employees', 'expenses', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    public function test_a_workflow_copies_its_statuses_and_rules_pointing_at_its_own_statuses(): void
    {
        $this->seed(WorkWorkflowSeeder::class);

        $original = WorkWorkflow::default()->load('statuses');
        [$first, $second] = [$original->statuses[0], $original->statuses[1]];

        WorkWorkflowRule::create([
            'work_workflow_id' => $original->getKey(),
            'kind' => WorkWorkflowRule::ONLY_TO,
            'work_status_id' => $first->getKey(),
            'to_status_ids' => [$second->getKey()],
        ]);
        $original->forceFill(['review_status_id' => $second->getKey()])->save();

        Livewire::test(EditWorkWorkflow::class, ['record' => $original->getRouteKey()])
            ->assertActionVisible('replicateRecord')
            ->callAction('replicateRecord', ['name' => 'Client approvals'])
            ->assertHasNoActionErrors();

        $copy = WorkWorkflow::query()->where('name', 'Client approvals')->firstOrFail()->load(['statuses', 'rules']);

        $this->assertFalse($copy->is_default);
        $this->assertSame($original->statuses->pluck('name')->all(), $copy->statuses->pluck('name')->all());
        $this->assertEmpty(array_intersect($original->statuses->modelKeys(), $copy->statuses->modelKeys()));

        $copyStatus = fn (string $name): int => (int) $copy->statuses->firstWhere('name', $name)->getKey();

        $rule = $copy->rules->sole();
        $this->assertSame($copyStatus($first->name), (int) $rule->work_status_id);
        $this->assertSame([$copyStatus($second->name)], array_map('intval', $rule->to_status_ids));
        $this->assertSame($copyStatus($second->name), (int) $copy->review_status_id);

        // The original is untouched.
        $this->assertTrue($original->fresh()->is_default);
        $this->assertSame(1, $original->rules()->count());
    }

    public function test_a_job_budget_copies_as_the_next_draft_version_with_its_lines(): void
    {
        $job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $code = CostCode::create(['code' => '03.100', 'name' => 'Concrete', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 'm3']);

        $budget = JobBudget::create([
            'job_id' => $job->getKey(), 'version_no' => 1, 'name' => 'Original budget',
            'kind' => JobBudget::KIND_ORIGINAL, 'status' => JobBudget::STATUS_APPROVED,
            'is_baseline' => true, 'is_current' => true, 'approved_at' => now(),
        ]);
        $budget->lines()->create([
            'job_id' => $job->getKey(), 'cost_code_id' => $code->getKey(), 'cost_type' => 'material',
            'quantity' => 100, 'unit_rate' => 18000, 'amount' => 1_800_000,
        ]);

        $copy = app(RecordReplicator::class)->replicate($budget);

        $this->assertSame(2, (int) $copy->version_no);
        $this->assertSame(JobBudget::STATUS_DRAFT, $copy->status);
        $this->assertSame(JobBudget::KIND_REVISION, $copy->kind);
        $this->assertFalse((bool) $copy->fresh()->is_current);
        $this->assertFalse((bool) $copy->fresh()->is_baseline);
        $this->assertNull($copy->approved_at);
        $this->assertSame(1_800_000.0, $copy->budgetAtCompletion());

        // Approval decides the current version, not copying: the original still is.
        $this->assertSame($budget->getKey(), JobBudget::currentFor($job)?->getKey());
    }

    public function test_an_expense_claim_copies_as_a_draft_nobody_has_approved_or_posted(): void
    {
        $claimant = $this->makeUser('Employee', 'claimant-copy@test.local');
        $employee = Employee::create(['user_id' => $claimant->id, 'employee_id' => 'EMP-COPY', 'gender' => 'Male', 'phone' => '0300-0000000']);

        $claim = ExpenseClaim::create([
            'employee_id' => $employee->id, 'claimed_on' => '2026-08-02', 'description' => 'Monthly travel',
            'amount' => 4_500, 'submitted_by' => $claimant->id, 'receipt_path' => 'receipts/taxi.pdf',
        ]);
        $claim->forceFill([
            'status' => ExpenseClaim::STATUS_REFUSED, 'decided_by' => $claimant->id, 'decided_at' => now(),
            'refusal_reason' => 'No receipt for the return leg',
        ])->save();

        $copy = app(RecordReplicator::class)->replicate($claim)->fresh();

        $this->assertSame(ExpenseClaim::STATUS_DRAFT, $copy->status);
        $this->assertSame(now()->toDateString(), $copy->claimed_on->toDateString());
        $this->assertSame('Monthly travel', $copy->description);
        $this->assertNull($copy->receipt_path);
        $this->assertNull($copy->decided_by);
        $this->assertNull($copy->refusal_reason);
        $this->assertNull($copy->journal_entry_id);
        $this->assertSame(0, $copy->approvals()->count());
    }

    public function test_a_fixed_asset_copies_with_its_own_tag_and_nothing_depreciated(): void
    {
        $asset = FixedAsset::create([
            'name' => 'Site laptop', 'account_id' => Account::where('code', '1400')->firstOrFail()->id,
            'purchase_date' => '2026-07-01', 'purchase_cost' => 360_000, 'depreciation_method' => 'straight_line',
            'useful_life_months' => 36, 'salvage_value' => 0,
        ]);
        $asset->forceFill(['accumulated_depreciation' => 120_000, 'status' => FixedAsset::STATUS_DISPOSED, 'disposed_at' => now()])->save();

        $copy = app(RecordReplicator::class)->replicate($asset)->fresh();

        $this->assertNotSame($asset->asset_code, $copy->asset_code);
        $this->assertStringStartsWith('FA-', $copy->asset_code);
        $this->assertSame(0.0, (float) $copy->accumulated_depreciation);
        $this->assertSame(FixedAsset::STATUS_ACTIVE, $copy->status);
        $this->assertNull($copy->disposed_at);
        $this->assertSame(360_000.0, $copy->book_value);
        $this->assertSame(0, $copy->journalEntries()->count());
    }

    public function test_a_holiday_asks_for_a_date_and_suggests_the_same_day_a_year_on(): void
    {
        $holiday = Holiday::create(['date' => '2026-08-14', 'name' => 'Independence Day', 'kind' => Holiday::KIND_PUBLIC]);

        $suggest = $holiday->replicationUniqueFields()['date'];
        $this->assertSame('2027-08-14', $suggest($holiday));

        // Taken already, so the next free day is offered rather than a value that cannot be saved.
        Holiday::create(['date' => '2027-08-14', 'name' => 'Independence Day', 'kind' => Holiday::KIND_PUBLIC]);
        $this->assertSame('2027-08-15', $suggest($holiday));

        Livewire::test(ListHolidays::class)
            ->callAction(TestAction::make('replicateRecord')->table($holiday), ['date' => '2028-08-14'])
            ->assertHasNoActionErrors();

        $copy = Holiday::query()->whereDate('date', '2028-08-14')->sole();
        $this->assertSame('Independence Day', $copy->name);

        // A date already on the calendar is refused, not saved as a duplicate.
        Livewire::test(ListHolidays::class)
            ->callAction(TestAction::make('replicateRecord')->table($holiday), ['date' => '2027-08-14'])
            ->assertHasActionErrors(['date']);
    }

    public function test_a_record_opened_in_its_sheet_offers_replicate_there_too(): void
    {
        // Made here: nothing in this suite seeds tax rates. A second row so the list hydrates a collection.
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18, 'is_default' => true, 'is_active' => true]);
        TaxRate::create(['name' => 'Reduced 5%', 'rate' => 5, 'is_active' => true]);

        Livewire::test(ListTaxRates::class)
            ->mountAction(TestAction::make('edit')->table($rate))
            ->assertActionVisible([TestAction::make('edit')->table($rate), TestAction::make('replicateFromSheet')]);
    }
}
