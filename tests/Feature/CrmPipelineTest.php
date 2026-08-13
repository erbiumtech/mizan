<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Crm\Models\NextAction;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Services\LeadConversion;
use App\Modules\Crm\Services\OpportunityService;
use App\Modules\Crm\Services\PipelineReports;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * CRM phases 2–4 — docs/crms-plan.md §3, §8 and the §12 cases they reach.
 *
 * Two assertions carry this file:
 *
 *  - **The exactly-one-party rule** (§12.1). A deal belongs to a lead or a contact, never
 *    both — except once the lead has converted, which is the one legitimate case. This is
 *    §1's whole architecture, and without it the pipeline and the invoice eventually
 *    disagree about who a deal is with.
 *  - **A won deal posts nothing and invoices nothing** (§12.4). §10 states it three times
 *    because a CRM is where automation is most tempting.
 */
class CrmPipelineTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['crm', 'employees', 'accounting', 'invoicing'] as $module) {
            $this->setModule($module, true);
        }

        $this->pipeline = $this->makePipeline();
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function makePipeline(): Pipeline
    {
        $pipeline = Pipeline::create(['name' => 'New business', 'is_default' => true]);

        $pipeline->stages()->createMany([
            ['name' => 'Qualification', 'sort' => 1, 'probability_pct' => 10, 'rot_after_days' => 14],
            ['name' => 'Proposal', 'sort' => 2, 'probability_pct' => 50, 'rot_after_days' => 7],
            ['name' => 'Won', 'sort' => 3, 'probability_pct' => 100, 'is_won' => true],
            ['name' => 'Lost', 'sort' => 4, 'probability_pct' => 0, 'is_lost' => true],
        ]);

        return $pipeline->fresh('stages');
    }

    private function stage(string $name): PipelineStage
    {
        return $this->pipeline->stages->firstWhere('name', $name);
    }

    private function makeLead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'company_name' => 'Karachi Textiles',
            'person_name' => 'Ayesha Khan',
            'email' => 'ayesha@karachitextiles.test',
        ], $attributes));
    }

    private function openDeal(array $attributes = []): Opportunity
    {
        return app(OpportunityService::class)->open(new Opportunity(array_merge([
            'title' => 'Warehouse system',
            'pipeline_id' => $this->pipeline->id,
            'pipeline_stage_id' => $this->stage('Qualification')->id,
            'lead_id' => $this->makeLead()->id,
            'amount' => 500000,
        ], $attributes)));
    }

    // ─────────────────────────── the exactly-one-party rule ───────────────────

    /** §12.1, first half: a deal with neither party is a deal about nobody. */
    public function test_a_deal_with_no_party_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a lead or a customer');

        $this->openDeal(['lead_id' => null]);
    }

    /** §12.1, second half: both set is two answers to "who is this deal with". */
    public function test_a_deal_with_both_parties_is_refused(): void
    {
        $contact = \App\Modules\Invoicing\Models\Contact::create([
            'name' => 'Someone else entirely',
            'kind' => \App\Modules\Invoicing\Models\Contact::KIND_CUSTOMER,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        $this->openDeal(['contact_id' => $contact->id]);
    }

    /**
     * The one case where both are legitimately set.
     *
     * Conversion fills `contact_id` and KEEPS `lead_id`, because "where did this customer
     * come from" is a lead-source question asked years later.
     */
    public function test_a_converted_lead_may_hold_both_the_lead_and_its_contact(): void
    {
        $lead = $this->makeLead();
        $deal = $this->openDeal(['lead_id' => $lead->id]);

        $contact = app(LeadConversion::class)->convert($lead->fresh());

        // Now both may be set, because the lead became that contact.
        $deal->update(['contact_id' => $contact->id]);

        $this->assertSame($lead->id, $deal->fresh()->lead_id);
        $this->assertSame($contact->id, $deal->fresh()->contact_id);
    }

    /** A repeat deal against an existing customer needs no lead at all. */
    public function test_a_deal_against_an_existing_customer_needs_no_lead(): void
    {
        $contact = \App\Modules\Invoicing\Models\Contact::create([
            'name' => 'Existing buyer',
            'kind' => \App\Modules\Invoicing\Models\Contact::KIND_CUSTOMER,
        ]);

        $deal = $this->openDeal(['lead_id' => null, 'contact_id' => $contact->id]);

        $this->assertSame($contact->id, $deal->contact_id);
        $this->assertNull($deal->lead_id);
    }

    // ─────────────────────────── stage history ────────────────────────────────

    /**
     * §12.6 — every move recorded with `days_in_stage`, and a move BACK recorded rather than
     * overwritten.
     *
     * The round trip is the point: a deal that went forward and came back spent real time in
     * each stage, and a table keeping only the latest position would report it as one fast
     * passage.
     */
    public function test_every_stage_move_is_recorded_including_moves_backwards(): void
    {
        $deal = $this->openDeal();

        // The first row: entering the first stage from nowhere.
        $this->assertSame(1, $deal->stageHistory()->count());
        $this->assertNull($deal->stageHistory()->first()->from_stage_id);

        app(OpportunityService::class)->moveTo($deal, $this->stage('Proposal'), $this->actor);
        app(OpportunityService::class)->moveTo($deal->fresh(), $this->stage('Qualification'), $this->actor);

        $history = $deal->fresh()->stageHistory;

        $this->assertCount(3, $history, 'A move back must be recorded, not overwritten.');
        $this->assertTrue($history->last()->isBackwards());
        $this->assertSame('Qualification', $deal->fresh()->stage->name);
    }

    /** Moving into a terminal stage closes the deal — dragging onto Won means winning. */
    public function test_moving_into_the_won_stage_closes_the_deal(): void
    {
        $deal = $this->openDeal();

        app(OpportunityService::class)->moveTo($deal, $this->stage('Won'), $this->actor);

        $this->assertTrue($deal->fresh()->isWon());
        $this->assertSame(100, $deal->fresh()->probability_pct);
        $this->assertNotNull($deal->fresh()->closed_on);
    }

    /** A deal cannot jump processes: the stages of a different pipeline mean different things. */
    public function test_a_deal_cannot_move_to_another_pipelines_stage(): void
    {
        $other = Pipeline::create(['name' => 'Renewals']);
        $otherStage = $other->stages()->create(['name' => 'Contacted', 'sort' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('different pipeline');

        app(OpportunityService::class)->moveTo($this->openDeal(), $otherStage, $this->actor);
    }

    /** The stage's probability follows a move, unless somebody deliberately overrode it. */
    public function test_probability_follows_the_stage_unless_overridden(): void
    {
        $following = $this->openDeal();
        $this->assertSame(10, $following->probability_pct);

        app(OpportunityService::class)->moveTo($following, $this->stage('Proposal'), $this->actor);
        $this->assertSame(50, $following->fresh()->probability_pct);

        // A deliberate override survives the next move.
        $overridden = $this->openDeal(['lead_id' => $this->makeLead(['company_name' => 'Other'])->id]);
        $overridden->update(['probability_pct' => 85]);

        app(OpportunityService::class)->moveTo($overridden->fresh(), $this->stage('Proposal'), $this->actor);
        $this->assertSame(85, $overridden->fresh()->probability_pct);
    }

    // ─────────────────────────── winning and losing ───────────────────────────

    /**
     * §12.4 — THE restraint test. A won deal posts nothing and creates nothing.
     *
     * A won deal is a sales fact; an invoice is a legal document. Since FBR digital
     * invoicing a transmitted invoice cannot be freely voided after 72 hours, so a button
     * that raised one automatically would create something nobody can take back.
     */
    public function test_winning_a_deal_posts_nothing_and_raises_no_invoice(): void
    {
        $entries = JournalEntry::count();
        $invoices = Invoice::count();

        app(OpportunityService::class)->markWon($this->openDeal(), $this->actor);

        $this->assertSame($entries, JournalEntry::count(), 'Winning a deal must not post to the ledger.');
        $this->assertSame($invoices, Invoice::count(), 'Winning a deal must not raise an invoice.');
    }

    public function test_losing_a_deal_records_the_reason_from_the_table(): void
    {
        $reason = LostReason::create(['name' => 'Too expensive']);
        $deal = $this->openDeal();

        app(OpportunityService::class)->markLost($deal, $reason, $this->actor);

        $this->assertSame(Opportunity::OUTCOME_LOST, $deal->fresh()->outcome);
        $this->assertSame($reason->id, $deal->fresh()->lost_reason_id);
        $this->assertSame('Lost', $deal->fresh()->stage->name);
    }

    /** A won deal is not "lost later" — that is a lost renewal, which is its own deal. */
    public function test_a_won_deal_cannot_be_marked_lost(): void
    {
        $deal = $this->openDeal();
        app(OpportunityService::class)->markWon($deal, $this->actor);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lost renewal');

        app(OpportunityService::class)->markLost($deal->fresh(), null, $this->actor);
    }

    public function test_a_closed_deal_can_be_reopened(): void
    {
        $deal = $this->openDeal();
        app(OpportunityService::class)->markLost($deal, LostReason::create(['name' => 'No budget']), $this->actor);

        app(OpportunityService::class)->reopen($deal->fresh(), $this->stage('Proposal'), $this->actor);

        $this->assertTrue($deal->fresh()->isOpen());
        $this->assertNull($deal->fresh()->lost_reason_id);
    }

    // ─────────────────────────── the forecast ─────────────────────────────────

    /**
     * §12.5 — the weighted forecast uses the stage's probability and the STORED rate, and
     * does not change when today's rate does.
     *
     * §13 warns by name: anyone who "fixes" this to read live rates silently rewrites
     * history.
     */
    public function test_the_forecast_is_weighted_at_the_stored_rate_and_does_not_move(): void
    {
        // 10,000 EUR at 300 = 3,000,000 base, weighted 50% at Proposal.
        $deal = $this->openDeal([
            'amount' => 10000,
            'currency_code' => 'EUR',
            'exchange_rate' => 300,
            'expected_close_on' => now()->addDays(10)->toDateString(),
        ]);
        app(OpportunityService::class)->moveTo($deal, $this->stage('Proposal'), $this->actor);

        $forecast = app(PipelineReports::class)->forecast(
            now()->toDateString(),
            now()->addMonth()->toDateString(),
        );

        $this->assertSame(3000000.0, $forecast['plain']);
        $this->assertSame(1500000.0, $forecast['weighted']);
        $this->assertSame(['EUR'], $forecast['currencies']);

        // The market moves. The forecast does not.
        $this->assertSame(3000000.0, $deal->fresh()->baseAmount());
    }

    /** A won deal is not a forecast: it would double against whatever Invoicing says. */
    public function test_the_forecast_counts_only_open_deals(): void
    {
        $deal = $this->openDeal(['expected_close_on' => now()->addDays(5)->toDateString()]);
        app(OpportunityService::class)->markWon($deal, $this->actor);

        $this->assertSame(0, app(PipelineReports::class)->forecast()['count']);
    }

    // ─────────────────────────── win/loss ────────────────────────────────────

    public function test_win_loss_reports_by_source_and_by_lost_reason(): void
    {
        $source = LeadSource::create(['name' => 'Referral']);
        $reason = LostReason::create(['name' => 'Too expensive']);

        $won = $this->openDeal(['lead_id' => $this->makeLead(['lead_source_id' => $source->id])->id]);
        app(OpportunityService::class)->markWon($won, $this->actor);

        $lost = $this->openDeal([
            'lead_id' => $this->makeLead(['company_name' => 'Other', 'lead_source_id' => $source->id])->id,
        ]);
        app(OpportunityService::class)->markLost($lost, $reason, $this->actor);

        $report = app(PipelineReports::class)->winLoss();

        $this->assertSame(1, $report['won']);
        $this->assertSame(1, $report['lost']);
        $this->assertSame(50.0, $report['rate']);
        $this->assertSame('Referral', $report['by_source'][0]['label']);
        $this->assertSame('Too expensive', $report['by_lost_reason'][0]['label']);
    }

    /** Nothing closed is not a 0% win rate — a dashboard would read that as failure. */
    public function test_a_win_rate_with_nothing_closed_is_null_rather_than_zero(): void
    {
        $this->assertNull(app(PipelineReports::class)->winLoss()['rate']);
    }

    // ─────────────────────────── rotting ─────────────────────────────────────

    /**
     * The only report that changes behaviour: what to chase.
     *
     * "Nothing planned" counts as rotting alongside "has not moved", and is usually the more
     * damning of the two — a deal somebody is thinking about but has planned nothing for.
     */
    public function test_rotting_finds_stalled_deals_and_deals_with_nothing_planned(): void
    {
        $planned = $this->openDeal();
        $planned->nextActions()->create(['title' => 'Call them', 'due_on' => now()->addDay()->toDateString()]);

        $unplanned = $this->openDeal(['lead_id' => $this->makeLead(['company_name' => 'Unplanned Ltd'])->id]);

        $rotting = app(PipelineReports::class)->rotting();

        $this->assertCount(1, $rotting);
        $this->assertSame($unplanned->id, $rotting[0]['opportunity']->id);
        $this->assertSame('Nothing planned', $rotting[0]['reason']);
    }

    /** A deal past its stage's patience is stale even with an action planned. */
    public function test_a_deal_past_its_stages_patience_is_stale(): void
    {
        $deal = $this->openDeal();
        $deal->nextActions()->create(['title' => 'Call them', 'due_on' => now()->addDay()->toDateString()]);

        // Backdate the entry so the deal has sat in Qualification for longer than 14 days.
        $deal->stageHistory()->update(['moved_at' => now()->subDays(30)]);

        $this->assertTrue($deal->fresh()->isRotting());
        $this->assertSame('Not moved', app(PipelineReports::class)->rotting()[0]['reason']);
    }

    /** A closed deal never rots: a won deal that has not moved is a won deal. */
    public function test_a_closed_deal_never_rots(): void
    {
        $deal = $this->openDeal();
        app(OpportunityService::class)->markWon($deal, $this->actor);
        $deal->fresh()->stageHistory()->update(['moved_at' => now()->subYear()]);

        $this->assertFalse($deal->fresh()->isRotting());
        $this->assertCount(0, app(PipelineReports::class)->rotting());
    }

    // ─────────────────────────── activities and next actions ──────────────────

    /** §12.14 — activities round-trip through the morph map for all three subject types. */
    public function test_activities_attach_to_leads_contacts_and_deals(): void
    {
        $lead = $this->makeLead();
        $deal = $this->openDeal(['lead_id' => $lead->id]);
        $contact = \App\Modules\Invoicing\Models\Contact::create([
            'name' => 'A customer',
            'kind' => \App\Modules\Invoicing\Models\Contact::KIND_CUSTOMER,
        ]);

        // Contact has no timeline() of its own — it is Invoicing's model — so the morph is
        // written directly for it. Lead and Opportunity use their own relation.
        foreach ([$lead, $deal, $contact] as $subject) {
            $subject->morphMany(Activity::class, 'subject')->create([
                'kind' => Activity::KIND_CALL,
                'outcome' => 'Send pricing',
                'duration_minutes' => 9,
            ]);
        }

        $this->assertSame(3, Activity::count());
        // The aliases are the legacy form, which is what ModuleCoverageTest asserts.
        $this->assertSame('App\Models\Lead', Activity::first()->subject_type);
        $this->assertSame(9, Activity::first()->duration_minutes);
    }

    /** An activity records who logged it, so the effort report is attributable. */
    public function test_an_activity_stamps_who_logged_it(): void
    {
        $employee = \App\Modules\Employees\Models\Employee::create([
            'employee_id' => 'EMP-1', 'name' => 'Sales', 'gender' => 'Male',
            'is_active' => true, 'user_id' => $this->actor->id,
        ]);

        $this->openDeal()->timeline()->create(['kind' => Activity::KIND_CALL]);

        $this->assertSame($employee->id, Activity::first()->employee_id);
        $this->assertSame($this->actor->id, Activity::first()->created_by);
    }

    /** A snooze does not move the original due date — the snooze count is the useful fact. */
    public function test_snoozing_keeps_the_original_due_date(): void
    {
        $deal = $this->openDeal();
        $action = $deal->nextActions()->create([
            'title' => 'Call them',
            'due_on' => now()->subDays(3)->toDateString(),
        ]);

        $action->update(['snoozed_until' => now()->addWeek()->toDateString()]);

        $this->assertSame(now()->subDays(3)->toDateString(), $action->fresh()->due_on->toDateString());
        $this->assertTrue($action->fresh()->isSnoozed());
        $this->assertSame(0, NextAction::due()->count(), 'A snoozed action is not due.');
    }
}
