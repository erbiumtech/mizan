<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages\CreateCostEntry;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages\EditCostEntry;
use App\Modules\ConstructionCosting\Filament\Settings\ConstructionCostingSettingsSection;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Services\ConstructionGlPostingService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\PeriodCloseService;
use App\Modules\ConstructionCosting\Support\CloseCheck;
use App\Modules\Core\Models\CompanyModule;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A cost typed on the Cost entries screen can reach the ledger.
 *
 * The bug: the form defaulted to Pending and offered no posting rule, so a manual non-labour cost matched no rule,
 * could never post, and blocked the month's close until somebody forced it. The form now asks for the rule, and
 * which treatment a manual entry starts with is a company setting.
 */
class ConstructionManualCostEntryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $overheads;

    private CostCode $labour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'manualcost@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->overheads = CostCode::create(['code' => '08.200', 'name' => 'Site accommodation', 'cost_type' => CostCode::TYPE_OTHER]);
        $this->labour = CostCode::create(['code' => '08.100', 'name' => 'Site supervision', 'cost_type' => CostCode::TYPE_LABOUR]);
    }

    /** @return array<string, mixed> */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $this->overheads->getKey(),
            'kind' => CostEntry::KIND_ACTUAL,
            'amount' => 185_000,
            'incurred_on' => '2026-08-10',
            'reference' => 'INV-CAB-1021',
        ], $overrides);
    }

    private function nominate(string $code, string $kind, ?string $purpose = null): void
    {
        ControlAccount::create([
            'account_id' => Account::create(['code' => $code, 'name' => "Account {$code}", 'type' => $kind === ControlAccount::KIND_COST ? 'expense' : 'liability'])->getKey(),
            'kind' => $kind,
            'purpose' => $purpose,
        ]);
    }

    public function test_a_pending_non_labour_entry_must_name_its_posting_rule(): void
    {
        Livewire::test(CreateCostEntry::class)
            ->fillForm($this->entry(['gl_treatment' => CostEntry::GL_PENDING]))
            ->call('create')
            ->assertHasFormErrors(['gl_purpose' => 'required']);

        $this->assertSame(0, CostEntry::count());
    }

    public function test_a_labour_entry_may_leave_the_rule_to_the_posting(): void
    {
        Livewire::test(CreateCostEntry::class)
            ->fillForm($this->entry(['cost_code_id' => $this->labour->getKey(), 'gl_treatment' => CostEntry::GL_PENDING]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(CostEntry::query()->firstOrFail()->gl_purpose);
    }

    /** The point of the fix: with a rule chosen, the entry posts and the month's close no longer waits on it. */
    public function test_an_entry_with_a_rule_posts_and_stops_blocking_the_close(): void
    {
        $this->nominate('5660', ControlAccount::KIND_COST);
        $this->nominate('5690', ControlAccount::KIND_RECOVERY, 'overhead_allocation');

        Livewire::test(CreateCostEntry::class)
            ->fillForm($this->entry(['gl_treatment' => CostEntry::GL_PENDING, 'gl_purpose' => 'overhead_allocation']))
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = CostEntry::query()->firstOrFail();
        $this->assertSame('overhead_allocation', $entry->gl_purpose);

        $plan = app(ConstructionGlPostingService::class)->plan('2026-08-01');
        $this->assertStringNotContainsString('No posting rule matches', $plan->describe());

        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $this->assertNotNull($entry->refresh()->posted_to_gl_at);
        $pending = collect(app(PeriodCloseService::class)->checks(CostPeriod::forDate('2026-08-10'))->checks)
            ->first(fn (CloseCheck $check): bool => $check->key === 'pending');
        $this->assertTrue($pending->passed);
    }

    public function test_a_manual_entry_starts_pending_by_default(): void
    {
        $this->assertSame(CostEntry::GL_PENDING, Livewire::test(CreateCostEntry::class)->get('data.gl_treatment'));
    }

    public function test_a_company_can_start_manual_entries_as_mirrored(): void
    {
        $section = app(ConstructionCostingSettingsSection::class);
        $section->save(['construction_costing_manual_gl_treatment' => CostEntry::GL_MIRRORED] + $this->tolerances());

        $this->assertSame(CostEntry::GL_MIRRORED, $section->fill()['construction_costing_manual_gl_treatment']);

        Livewire::test(CreateCostEntry::class)
            ->assertSet('data.gl_treatment', CostEntry::GL_MIRRORED)
            ->fillForm($this->entry())
            ->call('create')
            ->assertHasNoFormErrors();

        // Mirrored needs no rule: the ledger already has it.
        $this->assertSame(CostEntry::GL_MIRRORED, CostEntry::query()->firstOrFail()->gl_treatment);

        $section->save(['construction_costing_manual_gl_treatment' => CostEntry::GL_PENDING] + $this->tolerances());
        $this->assertSame(CostEntry::GL_PENDING, Livewire::test(CreateCostEntry::class)->get('data.gl_treatment'));
    }

    public function test_an_unknown_treatment_saves_as_the_default(): void
    {
        app(ConstructionCostingSettingsSection::class)->save(['construction_costing_manual_gl_treatment' => CostEntry::GL_POSTED] + $this->tolerances());

        $this->assertSame(CostEntry::GL_PENDING, setting('construction.costing.manual_gl_treatment'), 'posted is the system\'s to set');
    }

    /** A reversal written before the fix can be opened to give it a rule; its kind is shown and kept. */
    public function test_a_reversal_can_be_given_a_posting_rule(): void
    {
        $original = app(CostLedger::class)->record($this->job, $this->overheads, ['amount' => 140_000, 'incurred_on' => '2026-08-10', 'kind' => CostEntry::KIND_ACCRUAL]);
        $reversal = app(CostLedger::class)->reverse($original, 'Bill arrived');

        Livewire::test(EditCostEntry::class, ['record' => $reversal->getRouteKey()])
            ->fillForm(['gl_purpose' => 'overhead_allocation'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('overhead_allocation', $reversal->refresh()->gl_purpose);
        $this->assertSame(CostEntry::KIND_REVERSAL, $reversal->kind);
    }

    public function test_an_entry_the_ledger_has_posted_has_no_edit_page(): void
    {
        $entry = app(CostLedger::class)->record($this->job, $this->overheads, ['amount' => 1_000, 'incurred_on' => '2026-08-10']);
        $entry->update(['posted_to_gl_at' => now()]);

        Livewire::test(EditCostEntry::class, ['record' => $entry->getRouteKey()])->assertForbidden();
    }

    /** @return array<string, string> */
    private function tolerances(): array
    {
        return ['construction_match_quantity_percent' => '', 'construction_match_price_percent' => '', 'construction_match_minimum_amount' => ''];
    }
}
