<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages\CreateContract;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages\EditContract;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages\ListContracts;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\RelationManagers\ItemsRelationManager;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionContracts\Support\ContractVocabulary;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The head contract and its item schedule — `docs/construction-management-plan.md` §8, Phase 4a.
 *
 * Three properties carry this file, and each of them is the reason a column exists rather than a preference.
 *
 *  - **The schedule freezes at execution.** `scheduled_value` follows quantity × rate while the contract is a
 *    draft and stops at execution, because it is the figure the parties signed. If it could shrink afterwards,
 *    a certificate's "work completed to date" would exceed its "scheduled value" and the printed form would be
 *    arithmetically impossible — worse than merely wrong, because the page cannot be right.
 *  - **The measurement basis is not the contract standard.** AIA contracts are routinely unit-price and FIDIC
 *    Yellow is lump sum, so they are two columns and the second is what decides remeasurement. §8.1 calls
 *    conflating them a real error rather than a tidiness point.
 *  - **Expiry of the defects period is computed.** Stored, it would stop agreeing with a completion date
 *    somebody corrected last week, and the second retention release hangs off it.
 *
 * And one that is a claim the plan makes and this file discharges: **the dual-standard model is one set of
 * tables**. The same rows serve a Bill of Quantities and a Schedule of Values; the difference is which columns
 * are null and which words print (§8.2, §8.3).
 */
class ConstructionContractTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private ContractService $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'contracts@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create([
            'code' => 'J-2026-014',
            'name' => 'Tower',
            'contract_standard' => ContractVocabulary::FIDIC,
            'contract_sum' => 500_000_000,
            'retention_pct' => 10,
            'retention_cap_pct' => 5,
            'retention_first_release_pct' => 50,
            'payment_terms_days' => 56,
            'defects_period_days' => 365,
        ]);

        $this->contracts = app(ContractService::class);
    }

    private function contract(array $attributes = []): Contract
    {
        return $this->contracts->create($this->job, $attributes);
    }

    private function withSchedule(array $attributes = []): Contract
    {
        $contract = $this->contract($attributes);

        $this->contracts->addItem($contract, [
            'item_no' => '2.1', 'description' => 'Reinforced concrete to slabs',
            'unit' => 'm3', 'quantity' => 250, 'rate' => 20_000,
        ]);

        return $contract->refresh();
    }

    // ------------------------------------------------------------------ creation

    /**
     * The job's tender terms seed the contract, and are then read no more.
     *
     * Two live sources for "what is the retention percentage" is how a certificate comes to disagree with the
     * contract somebody signed.
     */
    public function test_a_contract_is_seeded_from_the_jobs_commercial_terms(): void
    {
        $contract = $this->contract();

        // Compared numerically rather than as strings: the two drivers disagree about whether a decimal comes
        // back as '10.00' or 10, and the figure is what this test is about.
        $this->assertEquals(500_000_000, $contract->contract_sum);
        $this->assertEquals(10, $contract->retention_percent);
        $this->assertEquals(5, $contract->retention_limit_percent);
        $this->assertSame(56, $contract->payment_terms_days);
        $this->assertSame(365, $contract->defects_period_days);
        $this->assertSame(Contract::STATUS_DRAFT, $contract->status);
    }

    /** What the form supplies wins over the seed, or the fields on the screen would be decoration. */
    public function test_what_the_caller_gives_beats_the_seeded_figure(): void
    {
        $contract = $this->contract(['retention_percent' => 7.5, 'title' => 'Enabling works']);

        $this->assertEquals(7.5, $contract->retention_percent);
        $this->assertSame('Enabling works', $contract->title);
    }

    /** Per job and per side, and with no gaps — a missing number is a question somebody has to answer later. */
    public function test_numbering_runs_per_job_and_per_side(): void
    {
        $this->assertSame('J-2026-014-C-1', $this->contract()->contract_number);
        $this->assertSame('J-2026-014-C-2', $this->contract()->contract_number);
        $this->assertSame('J-2026-014-SC-1', $this->contract(['side' => Contract::SIDE_PAYABLE])->contract_number);
    }

    /** The standard is copied down from the job, so a job need not lie about which family it belongs to. */
    public function test_the_standard_comes_from_the_job_and_seeds_the_release_rule(): void
    {
        $this->assertSame(Contract::RELEASE_FIDIC_TWO_STAGE, $this->contract()->retention_release_rule);

        $this->job->update(['contract_standard' => ContractVocabulary::AIA]);

        $this->assertSame(Contract::RELEASE_AIA_SUBSTANTIAL, $this->contract()->retention_release_rule);
    }

    /**
     * The release rule is a default rather than a consequence.
     *
     * A FIDIC contract with a negotiated single-stage release is ordinary, and reading the standard at release
     * time would quietly overrule what the parties agreed.
     */
    public function test_the_release_rule_can_disagree_with_the_standard(): void
    {
        $contract = $this->contract(['retention_release_rule' => Contract::RELEASE_SINGLE_STAGE]);

        $this->assertSame(ContractVocabulary::FIDIC, $contract->contract_standard);
        $this->assertSame(Contract::RELEASE_SINGLE_STAGE, $contract->retention_release_rule);
    }

    // ------------------------------------------------------------------ vocabulary

    /** §8.3's table is the whole of what the standard drives. */
    public function test_each_standard_speaks_its_own_language(): void
    {
        $fidic = ContractVocabulary::for(ContractVocabulary::FIDIC);
        $aia = ContractVocabulary::for(ContractVocabulary::AIA);
        $custom = ContractVocabulary::for(ContractVocabulary::CUSTOM);

        $this->assertSame('Bill of Quantities', $fidic->itemSchedule());
        $this->assertSame('Schedule of Values', $aia->itemSchedule());
        $this->assertSame('Contract Schedule', $custom->itemSchedule());

        $this->assertSame('Variation', $fidic->change());
        $this->assertSame('Change Order', $aia->change());

        $this->assertSame('Engineer', $fidic->certifier());
        $this->assertSame('Architect', $aia->certifier());

        $this->assertSame('Taking-Over Certificate', $fidic->completionEvent());
        $this->assertSame('Substantial Completion', $aia->completionEvent());

        $this->assertSame('Defects Notification Period', $fidic->defectsPeriod());
        $this->assertSame('Correction Period', $aia->defectsPeriod());
    }

    /**
     * Numbers are not zero-padded.
     *
     * A certificate number is quoted in correspondence and at adjudication as the number it is, and `IPC-007`
     * and `IPC-7` being the same certificate is a question nobody should have to answer.
     */
    public function test_document_numbers_follow_the_standards_series(): void
    {
        $this->assertSame('IPC-7', ContractVocabulary::for(ContractVocabulary::FIDIC)->number('certificate', 7));
        $this->assertSame('APP-7', ContractVocabulary::for(ContractVocabulary::AIA)->number('certificate', 7));
        $this->assertSame('VO-12', ContractVocabulary::for(ContractVocabulary::FIDIC)->number('change', 12));
        $this->assertSame('CO-12', ContractVocabulary::for(ContractVocabulary::AIA)->number('change', 12));
    }

    /** An unknown standard or key throws rather than printing a blank on a contract. */
    public function test_the_vocabulary_refuses_a_standard_it_does_not_know(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractVocabulary::for('nec4');
    }

    // ------------------------------------------------------------------ the schedule

    public function test_the_scheduled_value_follows_quantity_times_rate_while_the_contract_is_a_draft(): void
    {
        $contract = $this->withSchedule();
        $item = $contract->items()->firstOrFail();

        $this->assertEquals(5_000_000, $item->scheduled_value);

        $item->update(['quantity' => 300]);

        $this->assertEquals(6_000_000, $item->refresh()->scheduled_value);
    }

    /**
     * **The exit condition of this sub-phase.** Execution freezes it.
     *
     * The stored figure stands whatever anybody edits afterwards, because a certificate measured against a
     * value that later shrank would print a completed figure exceeding its own scheduled value.
     */
    public function test_execution_freezes_the_scheduled_value_against_a_later_remeasure(): void
    {
        $contract = $this->withSchedule();
        $this->contracts->execute($contract);

        $item = $contract->items()->firstOrFail();
        $item->update(['quantity' => 900]);

        $this->assertEquals(900, $item->refresh()->quantity, 'the quantity is remeasured');
        $this->assertEquals(5_000_000, $item->scheduled_value, 'and the value the parties signed does not move');
    }

    /** A Schedule of Values line carries no quantity and no rate, and that is not an error. */
    public function test_an_aia_line_needs_only_a_number_a_description_and_a_value(): void
    {
        $contract = $this->contract(['contract_standard' => ContractVocabulary::AIA]);

        $item = $this->contracts->addItem($contract, [
            'item_no' => '03 30 00',
            'description' => 'Cast-in-place concrete',
            'item_type' => ContractItem::TYPE_LUMP_SUM,
            'scheduled_value' => 4_200_000,
        ]);

        $this->assertNull($item->quantity);
        $this->assertNull($item->rate);
        $this->assertEquals(4_200_000, $item->scheduled_value);
    }

    public function test_an_executed_schedule_refuses_a_new_line_and_names_the_alternative(): void
    {
        $contract = $this->withSchedule();
        $this->contracts->execute($contract);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Raise a variation');

        $this->contracts->addItem($contract, ['item_no' => '9.9', 'description' => 'Extra', 'scheduled_value' => 1]);
    }

    /** And it says "change order" on an AIA contract, because that is what the client's paperwork calls it. */
    public function test_the_refusal_speaks_the_contracts_own_language(): void
    {
        $contract = $this->withSchedule(['contract_standard' => ContractVocabulary::AIA]);
        $this->contracts->execute($contract);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Raise a change order');

        $this->contracts->addItem($contract, ['item_no' => '9.9', 'description' => 'Extra', 'scheduled_value' => 1]);
    }

    /** A contract with a sum and no lines has nothing to put in column C. */
    public function test_a_contract_with_no_schedule_cannot_be_executed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no bill of quantities lines');

        $this->contracts->execute($this->contract());
    }

    public function test_executing_twice_is_refused(): void
    {
        $contract = $this->withSchedule();
        $this->contracts->execute($contract);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already executed');

        $this->contracts->execute($contract->refresh());
    }

    /**
     * The schedule total and the contract sum are allowed to differ, and both are shown.
     *
     * On a lump-sum contract they differ by whatever was not broken down. A screen showing one of them would
     * hide the gap rather than resolve it.
     */
    public function test_the_schedule_total_is_the_priced_lines_and_not_the_contract_sum(): void
    {
        $contract = $this->withSchedule();

        $this->assertSame(5_000_000.0, $contract->scheduleTotal());
        $this->assertEquals(500_000_000, $contract->contract_sum);

        $contract->items()->firstOrFail()->update(['is_active' => false]);

        $this->assertSame(0.0, $contract->refresh()->scheduleTotal(), 'a superseded line is out of the total');
    }

    /** An omission is a negative line, which is ugly on the page and correct in the ledger (§9). */
    public function test_an_omission_reads_as_negative_rather_than_reducing_the_line_it_omits(): void
    {
        $contract = $this->withSchedule();

        $omission = $this->contracts->addItem($contract, [
            'item_no' => '2.1-OM', 'description' => 'Omit slab to grid E', 'scheduled_value' => -750_000,
        ]);

        $this->assertTrue($omission->isOmission());
        $this->assertSame(4_250_000.0, $contract->refresh()->scheduleTotal());
    }

    // ------------------------------------------------------------------ the dates

    public function test_the_defects_period_expiry_is_computed_from_the_completion_date(): void
    {
        $contract = $this->contract([
            'practical_completion_date' => '2027-03-31',
            'defects_period_days' => 365,
        ]);

        // 2028 is a leap year, so 365 days from 31 March 2027 lands on the 30th — which is what a contract
        // written in *days* actually says, and the reason the column counts days rather than months.
        $this->assertSame('2028-03-30', $contract->defectsPeriodExpiry()->toDateString());

        // Corrected completion date, and the expiry follows it — which a stored column would not.
        $contract->update(['practical_completion_date' => '2027-04-30']);

        $this->assertSame('2028-04-29', $contract->refresh()->defectsPeriodExpiry()->toDateString());
    }

    /** Null rather than a guess while either half of the answer is missing. */
    public function test_there_is_no_expiry_before_completion(): void
    {
        $this->assertNull($this->contract(['practical_completion_date' => null])->defectsPeriodExpiry());
        $this->assertNull($this->contract([
            'practical_completion_date' => '2027-03-31',
            'defects_period_days' => null,
        ])->defectsPeriodExpiry());
    }

    /** An approved extension of time wins, which is the whole reason there are two columns. */
    public function test_the_completion_date_in_force_is_the_extended_one_when_there_is_one(): void
    {
        $contract = $this->contract([
            'contract_completion_date' => '2027-03-31',
            'extended_completion_date' => '2027-06-30',
        ]);

        $this->assertSame('2027-06-30', $contract->completionDate()->toDateString());
    }

    /**
     * The retention cap reads whichever way the contract states it.
     *
     * Converting a percentage into money at data-entry time would stop tracking the cap when the contract sum
     * moves on a remeasured job.
     */
    public function test_the_retention_cap_comes_from_either_column(): void
    {
        // 5% of 500,000,000.
        $this->assertSame(25_000_000.0, $this->contract()->retentionCap());

        $this->assertSame(9_000_000.0, $this->contract([
            'retention_limit_amount' => 9_000_000,
        ])->retentionCap());

        $this->assertNull($this->contract([
            'retention_limit_percent' => null,
            'retention_limit_amount' => null,
        ])->retentionCap());
    }

    // ------------------------------------------------------------------ measurement basis

    /** §8.1's real error, asserted: the basis is its own column and does not follow the standard. */
    public function test_the_measurement_basis_is_independent_of_the_contract_standard(): void
    {
        $aiaUnitPrice = $this->contract([
            'contract_standard' => ContractVocabulary::AIA,
            'measurement_basis' => Contract::BASIS_REMEASURED,
        ]);

        $fidicLumpSum = $this->contract([
            'contract_standard' => ContractVocabulary::FIDIC,
            'measurement_basis' => Contract::BASIS_LUMP_SUM,
        ]);

        $this->assertTrue($aiaUnitPrice->isRemeasured(), 'an AIA unit-price contract is remeasured');
        $this->assertFalse($fidicLumpSum->isRemeasured(), 'FIDIC Yellow is lump sum');
    }

    // ------------------------------------------------------------------ authorisation

    /**
     * Asked of a CEO rather than an Administrator, and that is not a detail.
     *
     * `AppServiceProvider` registers a `Gate::before` that waves an Administrator through every ability except
     * `create`, so a refusal asserted as an Administrator asserts nothing at all — the policy is never
     * consulted. The CEO is the most-privileged role the policies actually apply to, which makes it the right
     * user to ask "is this refused even for the person who can do everything else".
     */
    private function ceo(): \App\Modules\Core\Models\User
    {
        (new \Database\Seeders\RoleSeeder)->run();

        return $this->makeUser('CEO', 'contracts-ceo@test.local');
    }

    /** A line written by an approved variation is never edited: it is what the parties agreed the change was. */
    public function test_a_variation_line_is_not_editable_even_on_a_draft(): void
    {
        $contract = $this->withSchedule();
        $ordinary = $contract->items()->firstOrFail();

        $fromVariation = $this->contracts->addItem($contract, [
            'item_no' => 'VO-1.1', 'description' => 'Additional pile caps', 'scheduled_value' => 800_000,
            'source_variation_id' => 4242,
        ]);

        $ceo = $this->ceo();

        $this->assertTrue($ceo->can('update', $ordinary));
        $this->assertFalse($ceo->can('update', $fromVariation));
    }

    public function test_an_executed_contract_is_not_editable_and_not_deletable(): void
    {
        $contract = $this->withSchedule();
        $ceo = $this->ceo();

        $this->assertTrue($ceo->can('update', $contract));
        $this->assertTrue($ceo->can('execute', $contract));

        $this->contracts->execute($contract);
        $contract->refresh();

        $this->assertFalse($ceo->can('update', $contract));
        $this->assertFalse($ceo->can('execute', $contract));
        $this->assertFalse($ceo->can('delete', $contract));
    }

    /** A head contract with subcontracts under it is not deletable, whatever the permission says. */
    public function test_a_contract_with_subcontracts_is_not_deletable(): void
    {
        $head = $this->contract();
        $this->contract(['side' => Contract::SIDE_PAYABLE, 'parent_contract_id' => $head->getKey()]);

        $this->assertFalse($this->ceo()->can('delete', $head->refresh()));
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_renders_with_both_sides(): void
    {
        $this->contract(['title' => 'Main works']);
        $this->contract(['side' => Contract::SIDE_PAYABLE, 'title' => 'Piling subcontract']);

        Livewire::test(ListContracts::class)
            ->assertSuccessful()
            ->assertSee('Main works')
            ->assertSee('Piling subcontract')
            ->assertSee('J-2026-014-SC-1');
    }

    public function test_creating_a_contract_on_the_screen_goes_through_the_service(): void
    {
        Livewire::test(CreateContract::class)
            ->fillForm([
                'job_id' => $this->job->getKey(),
                'title' => 'Main works',
                'contract_standard' => ContractVocabulary::FIDIC,
                'side' => Contract::SIDE_RECEIVABLE,
                'measurement_basis' => Contract::BASIS_REMEASURED,
                'contract_sum' => 500_000_000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contract = Contract::query()->where('title', 'Main works')->firstOrFail();

        // Numbered by the service and seeded from the job, neither of which the form supplied.
        $this->assertSame('J-2026-014-C-1', $contract->contract_number);
        $this->assertEquals(10, $contract->retention_percent);
    }

    public function test_the_execute_action_freezes_the_schedule(): void
    {
        $contract = $this->withSchedule();

        Livewire::test(ListContracts::class)
            ->callTableAction('execute', $contract);

        $this->assertSame(Contract::STATUS_EXECUTED, $contract->refresh()->status);
    }

    /** The schedule tab closes once the contract is executed, because the service would refuse anyway. */
    public function test_the_schedule_tab_closes_on_execution(): void
    {
        $contract = $this->withSchedule();

        $this->itemsTab($contract)->assertActionVisible(TestAction::make('create')->table());

        $this->contracts->execute($contract);

        $this->itemsTab($contract->refresh())->assertActionHidden(TestAction::make('create')->table());
    }

    /** And the tab is titled in the contract's own vocabulary, which is §8.3's claim on a screen. */
    public function test_the_schedule_tab_is_titled_for_the_standard(): void
    {
        $fidic = $this->withSchedule();
        $aia = $this->withSchedule(['contract_standard' => ContractVocabulary::AIA]);

        $this->assertSame('Bill of Quantities', ItemsRelationManager::getTitle($fidic, EditContract::class));
        $this->assertSame('Schedule of Values', ItemsRelationManager::getTitle($aia, EditContract::class));
    }

    private function itemsTab(Contract $contract): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $contract,
            'pageClass' => EditContract::class,
        ]);
    }
}
