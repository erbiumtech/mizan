<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\Pages\ListVariations;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Models\Variation;
use App\Modules\ConstructionContracts\Models\VariationItem;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionContracts\Services\VariationService;
use App\Modules\ConstructionContracts\Support\ContractVocabulary;
use App\Modules\Core\Models\CompanyModule;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Variations and change orders — `docs/construction-management-plan.md` §9, Phase 4b.
 *
 * **The rule this file exists for is the sharpest one in §9**: a provisionally priced variation is excluded from
 * the certified contract sum and included in the forecast. Two scopes carry it — `agreed()` and `forecast()` —
 * and the section demands there be **no bare `where('status', 'approved')` anywhere in the module**, which
 * `test_no_bare_approved_status_check_exists_in_the_module` asserts against the source, because the rule is one
 * helpful line of code away from being broken silently. "One boolean, two audiences, and conflating them is how a
 * job reports a margin it does not have for two quarters running."
 *
 * The second is what approval writes. An `omit` is a **negative line**, never a reduction of the line it omits:
 * reducing the original would make every certificate already issued print a completed figure exceeding the
 * scheduled value it was measured against — impossible on the face of the form rather than merely wrong.
 */
class ConstructionVariationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private Contract $contract;

    private VariationService $variations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'variations@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower', 'contract_sum' => 500_000_000]);

        $contracts = app(ContractService::class);
        $this->contract = $contracts->create($this->job, ['title' => 'Main works']);
        $contracts->addItem($this->contract, [
            'item_no' => '2.1', 'description' => 'Concrete to slabs',
            'unit' => 'm3', 'quantity' => 250, 'rate' => 20_000,
        ]);
        $contracts->execute($this->contract);
        $this->contract->refresh();

        $this->variations = app(VariationService::class);
    }

    private function variation(array $attributes = []): Variation
    {
        return $this->variations->create($this->contract, $attributes + ['title' => 'Additional pile caps']);
    }

    /** Submitted, priced and ready for a decision — the state most of these tests start from. */
    private function pricedVariation(float $amount = 800_000): Variation
    {
        $variation = $this->variation();

        $this->variations->addItem($variation, [
            'action' => VariationItem::ACTION_ADD,
            'item_no' => '2.9', 'description' => 'Extra pile caps',
            'unit' => 'nr', 'quantity' => 8, 'rate' => $amount / 8,
        ]);

        $this->variations->submit($variation);

        return $this->variations->price($variation->refresh());
    }

    private function scheduleItem(string $itemNo): ?ContractItem
    {
        return ContractItem::query()
            ->where('contract_id', $this->contract->getKey())
            ->where('item_no', $itemNo)
            ->first();
    }

    // ------------------------------------------------------------------ raising

    public function test_a_variation_is_numbered_in_the_contracts_own_series(): void
    {
        $this->assertSame('VO-1', $this->variation()->variation_number);
        $this->assertSame('VO-2', $this->variation()->variation_number);
    }

    /** An AIA contract numbers its change orders CO-1 upward, from the same code (§8.3). */
    public function test_an_aia_contract_numbers_change_orders_instead(): void
    {
        $this->contract->update(['contract_standard' => ContractVocabulary::AIA]);

        $this->assertSame('CO-1', $this->variation()->variation_number);
    }

    /**
     * A draft contract is edited, not varied.
     *
     * Two ways to reach the same state with different audit trails is worse than one way that refuses.
     */
    public function test_a_draft_contract_cannot_be_varied(): void
    {
        $draft = app(ContractService::class)->create($this->job, ['title' => 'Enabling works']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('still a draft');

        $this->variations->create($draft);
    }

    public function test_an_omission_must_name_the_line_it_omits(): void
    {
        $variation = $this->variation();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name the schedule line');

        $this->variations->addItem($variation, ['action' => VariationItem::ACTION_OMIT, 'amount' => 100]);
    }

    public function test_an_addition_needs_the_number_it_will_print_as(): void
    {
        $variation = $this->variation();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('item number it will print as');

        $this->variations->addItem($variation, ['action' => VariationItem::ACTION_ADD, 'amount' => 100]);
    }

    // ------------------------------------------------------------------ the state machine

    public function test_the_states_run_in_order(): void
    {
        $variation = $this->variation();
        $this->assertSame(Variation::STATUS_DRAFT, $variation->status);

        $this->variations->addItem($variation, [
            'item_no' => '2.9', 'description' => 'Extra', 'quantity' => 1, 'rate' => 800_000,
        ]);

        $this->assertSame(Variation::STATUS_SUBMITTED, $this->variations->submit($variation)->status);
        $this->assertSame(Variation::STATUS_PRICED, $this->variations->price($variation->refresh())->status);
        $this->assertSame(Variation::STATUS_APPROVED, $this->variations->approve($variation->refresh())->status);
        $this->assertSame(Variation::STATUS_INCORPORATED, $this->variations->incorporate($variation->refresh())->status);
    }

    public function test_a_state_cannot_be_skipped(): void
    {
        $variation = $this->variation();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be approved');

        $this->variations->approve($variation);
    }

    /** Pricing defaults to the sum of the lines, because that is what the lines are for. */
    public function test_pricing_takes_the_sum_of_the_lines_unless_the_certifier_says_otherwise(): void
    {
        $variation = $this->pricedVariation(800_000);

        $this->assertEquals(800_000, $variation->assessed_amount);

        $this->variations->price($variation->refresh(), 650_000);

        $this->assertEquals(650_000, $variation->refresh()->assessed_amount, "the certifier's own assessment stands");
        $this->assertEquals(800_000, $variation->itemsTotal(), 'and the lines are untouched');
    }

    public function test_a_variation_with_nothing_to_price_is_refused(): void
    {
        $variation = $this->variation();
        $this->variations->submit($variation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to price');

        $this->variations->price($variation->refresh());
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $variation = $this->pricedVariation();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->variations->reject($variation, '   ');
    }

    public function test_approving_with_no_agreed_amount_is_refused(): void
    {
        $variation = $this->variation();
        $this->variations->submit($variation);
        $this->variations->price($variation->refresh(), 0.0);
        // A zero assessment is a figure; a null one is not, so the refusal needs the null case.
        $variation->refresh()->update(['assessed_amount' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no agreed amount');

        $this->variations->approve($variation->refresh());
    }

    // ------------------------------------------- agreed versus forecast, the rule

    /**
     * **The exit condition of this sub-phase.** Approved in principle is forecast, not certified.
     *
     * The state construction lives in: instructed, work proceeding, price argued for months. Certifying it would
     * be money nobody agreed; forecasting it as zero would be a cost the job is already incurring.
     */
    public function test_a_provisionally_priced_variation_is_forecast_but_not_certified(): void
    {
        $variation = $this->pricedVariation(800_000);
        $this->variations->approveInPrinciple($variation->refresh(), 'low');

        $this->contract->refresh();

        $this->assertTrue($variation->refresh()->is_price_provisional);
        $this->assertSame(0.0, $this->contract->agreedVariationsNet(), 'nothing agreed yet');
        $this->assertSame(500_000_000.0, $this->contract->revisedSum(), 'so the certified sum has not moved');
        $this->assertSame(500_800_000.0, $this->contract->forecastSum(), 'and the forecast already carries it');
    }

    /** Approving clears the flag, which is what moves the money from the forecast into the certified sum. */
    public function test_approving_moves_the_money_into_the_certified_contract_sum(): void
    {
        $variation = $this->pricedVariation(800_000);
        $this->variations->approveInPrinciple($variation->refresh());
        $this->variations->approve($variation->refresh());

        $this->contract->refresh();

        $this->assertFalse($variation->refresh()->is_price_provisional);
        $this->assertNull($variation->provisional_confidence);
        $this->assertSame(800_000.0, $this->contract->agreedVariationsNet());
        $this->assertSame(500_800_000.0, $this->contract->revisedSum());
        // Both audiences now agree, which is the point: the gap between them is the unagreed money.
        $this->assertSame(500_800_000.0, $this->contract->forecastSum());
    }

    /**
     * The forecast reads the *effective* amount, not the approved column.
     *
     * A variation approved in principle has an assessed figure and no approved one, and summing the approved
     * column would forecast it as nothing — which is the same silent zero the whole rule exists to prevent.
     */
    public function test_the_forecast_reads_the_assessed_figure_when_nothing_is_approved_yet(): void
    {
        $variation = $this->pricedVariation(800_000);
        $this->variations->approveInPrinciple($variation->refresh());

        $this->assertNull($variation->refresh()->approved_amount);
        $this->assertSame(800_000.0, $variation->effectiveAmount());
        $this->assertSame(500_800_000.0, $this->contract->refresh()->forecastSum());
    }

    public function test_a_rejected_variation_is_in_neither_figure(): void
    {
        $variation = $this->pricedVariation(800_000);
        $this->variations->reject($variation->refresh(), 'Included in the original scope, clause 4.1.');

        $this->contract->refresh();

        $this->assertSame(500_000_000.0, $this->contract->revisedSum());
        $this->assertSame(500_000_000.0, $this->contract->forecastSum());
    }

    /**
     * §9's demand, asserted against the source of the whole module.
     *
     * Every reader of variation status must go through `agreed()` or `forecast()`. A bare
     * `where('status', 'approved')` is how a certificate comes to include provisional money: it reads correctly,
     * it passes review, and it is wrong only in the state the module was designed around.
     */
    public function test_no_bare_approved_status_check_exists_in_the_module(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path('Modules/ConstructionContracts')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $file->getContents();

            // The scopes themselves are the one legitimate place, and they live on the model.
            if ($file->getFilename() === 'Variation.php') {
                continue;
            }

            if (preg_match("/where\(\s*'status'\s*,\s*'?(Variation::)?STATUS_APPROVED/i", $source)
                || preg_match("/where\(\s*'status'\s*,\s*'approved'/i", $source)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These files query variation status directly instead of using the agreed() or forecast() scope.',
            'A provisionally priced variation belongs in the forecast and not in a certificate — §9.',
            '',
            ...$offenders,
        ]));
    }

    // ------------------------------------------------------------------ incorporation

    public function test_an_addition_becomes_a_new_schedule_line_that_names_its_variation(): void
    {
        $variation = $this->pricedVariation(800_000);
        $this->variations->approve($variation->refresh());
        $this->variations->incorporate($variation->refresh());

        $line = $this->scheduleItem('2.9');

        $this->assertNotNull($line);
        $this->assertEquals(800_000, $line->scheduled_value);
        $this->assertSame($variation->getKey(), $line->source_variation_id);
        $this->assertTrue($line->isVariationLine());
        // Traceable in both directions, which is what makes incorporation idempotent.
        $this->assertSame($line->getKey(), $variation->refresh()->items()->firstOrFail()->resulting_item_id);
    }

    /**
     * **An omission is a negative line and the original is untouched.**
     *
     * Reducing the original would make every certificate already issued print a completed figure exceeding the
     * scheduled value it was measured against.
     */
    public function test_an_omission_writes_a_negative_line_and_leaves_the_original_alone(): void
    {
        $original = $this->scheduleItem('2.1');
        $variation = $this->variation(['title' => 'Omit slab to grid E']);

        $this->variations->addItem($variation, [
            'action' => VariationItem::ACTION_OMIT,
            'contract_item_id' => $original->getKey(),
            'amount' => 750_000,
        ]);

        $this->variations->submit($variation);
        $this->variations->price($variation->refresh());
        $this->variations->approve($variation->refresh());
        $this->variations->incorporate($variation->refresh());

        $this->assertEquals(5_000_000, $original->refresh()->scheduled_value, 'the original does not move');

        $omission = $this->scheduleItem('2.1-OM');
        $this->assertNotNull($omission);
        $this->assertEquals(-750_000, $omission->scheduled_value);
        $this->assertTrue($omission->isOmission());
        $this->assertSame(ContractItem::TYPE_ADJUSTMENT, $omission->item_type);

        // And the schedule total is the net of the pair.
        $this->assertSame(4_250_000.0, $this->contract->refresh()->scheduleTotal());
    }

    /** A positive omission cannot quietly add money: incorporation makes the sign negative either way. */
    public function test_an_omission_written_positive_is_still_a_deduction(): void
    {
        $original = $this->scheduleItem('2.1');
        $variation = $this->variation();

        $this->variations->addItem($variation, [
            'action' => VariationItem::ACTION_OMIT,
            'contract_item_id' => $original->getKey(),
            'amount' => 750_000,
        ]);
        $this->variations->submit($variation);
        $this->variations->price($variation->refresh());
        $this->variations->approve($variation->refresh());
        $this->variations->incorporate($variation->refresh());

        $this->assertEquals(-750_000, $this->scheduleItem('2.1-OM')->scheduled_value);
    }

    /**
     * A remeasure edits in place and keeps what was there before.
     *
     * Safe because on a remeasured contract the quantity was always approximate, and every certificate line
     * carries its own frozen cumulative value regardless.
     */
    public function test_a_remeasure_edits_the_line_and_records_what_it_was(): void
    {
        $original = $this->scheduleItem('2.1');
        $variation = $this->variation(['title' => 'Remeasure slabs']);

        $item = $this->variations->addItem($variation, [
            'action' => VariationItem::ACTION_REMEASURE,
            'contract_item_id' => $original->getKey(),
            'quantity' => 310,
            'rate' => 20_000,
        ]);

        $this->variations->submit($variation);
        $this->variations->price($variation->refresh());
        $this->variations->approve($variation->refresh());
        $this->variations->incorporate($variation->refresh());

        $this->assertEquals(310, $original->refresh()->quantity);
        // 310 × 20,000 — recomputed on incorporation, which is the only thing allowed to move a frozen value.
        $this->assertEquals(6_200_000, $original->scheduled_value);

        $this->assertEquals(250, $item->refresh()->previous_quantity, 'the audit trail for the edit');
        $this->assertEquals(20_000, $item->previous_rate);
        $this->assertSame($original->getKey(), $item->resulting_item_id);
    }

    public function test_a_rate_change_keeps_the_quantity_and_records_the_old_rate(): void
    {
        $original = $this->scheduleItem('2.1');
        $variation = $this->variation();

        $item = $this->variations->addItem($variation, [
            'action' => VariationItem::ACTION_RATE_CHANGE,
            'contract_item_id' => $original->getKey(),
            'rate' => 23_000,
        ]);

        $this->variations->submit($variation);
        $this->variations->price($variation->refresh(), 750_000);
        $this->variations->approve($variation->refresh());
        $this->variations->incorporate($variation->refresh());

        $this->assertEquals(250, $original->refresh()->quantity);
        $this->assertEquals(23_000, $original->rate);
        $this->assertEquals(5_750_000, $original->scheduled_value);
        $this->assertEquals(20_000, $item->refresh()->previous_rate);
    }

    /**
     * A provisional price never reaches the schedule.
     *
     * The schedule is what certificates are measured against, so writing an unagreed figure into it would
     * certify money nobody agreed through the one door built to keep it out.
     */
    public function test_a_variation_approved_in_principle_cannot_be_written_into_the_schedule(): void
    {
        $variation = $this->pricedVariation();
        $this->variations->approveInPrinciple($variation->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an approved variation');

        $this->variations->incorporate($variation->refresh());
    }

    /** Incorporating twice changes nothing the second time. */
    public function test_incorporation_is_idempotent(): void
    {
        $variation = $this->pricedVariation(800_000);
        $this->variations->approve($variation->refresh());
        $this->variations->incorporate($variation->refresh());

        $before = ContractItem::query()->where('contract_id', $this->contract->getKey())->count();

        // Status is `incorporated` by now, so a second call refuses rather than duplicating — and the count is
        // the assertion that matters either way.
        try {
            $this->variations->incorporate($variation->refresh());
        } catch (InvalidArgumentException) {
            // Expected: the state machine refuses first.
        }

        $this->assertSame($before, ContractItem::query()->where('contract_id', $this->contract->getKey())->count());
    }

    public function test_an_approved_variation_refuses_a_new_line(): void
    {
        $variation = $this->pricedVariation();
        $this->variations->approve($variation->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('what the parties');

        $this->variations->addItem($variation->refresh(), ['item_no' => '9.9', 'amount' => 1]);
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_renders_and_shows_the_provisional_state(): void
    {
        $variation = $this->pricedVariation();
        $this->variations->approveInPrinciple($variation->refresh());

        Livewire::test(ListVariations::class)
            ->assertSuccessful()
            ->assertSee('VO-1')
            ->assertSee('Additional pile caps')
            ->assertSee('price provisional');
    }

    public function test_the_approve_action_agrees_the_money(): void
    {
        $variation = $this->pricedVariation(800_000);

        Livewire::test(ListVariations::class)
            ->callTableAction('approve', $variation, ['amount' => 720_000]);

        $variation->refresh();

        $this->assertSame(Variation::STATUS_APPROVED, $variation->status);
        $this->assertEquals(720_000, $variation->approved_amount);
        $this->assertSame(500_720_000.0, $this->contract->refresh()->revisedSum());
    }

    public function test_the_approve_in_principle_action_keeps_it_out_of_the_certified_sum(): void
    {
        $variation = $this->pricedVariation(800_000);

        Livewire::test(ListVariations::class)
            ->callTableAction('approveInPrinciple', $variation, ['confidence' => 'high']);

        $this->assertSame(Variation::STATUS_APPROVED_IN_PRINCIPLE, $variation->refresh()->status);
        $this->assertSame('high', $variation->provisional_confidence);
        $this->assertSame(500_000_000.0, $this->contract->refresh()->revisedSum());
    }
}
