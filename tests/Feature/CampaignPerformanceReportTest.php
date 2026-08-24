<?php

namespace Tests\Feature;

use App\Modules\Campaigns\Filament\Pages\CampaignPerformance;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Models\Contact;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Campaign performance — `docs/reports-expansion-plan.md` Phase 3.11.
 *
 * The plan asks for "sends, failures and reasons per campaign", and `CampaignSend`'s own docblock says which
 * of those matters most: `skipped_no_consent` "has to be reported as a distinct figure rather than a silence —
 * otherwise nobody can tell a campaign that reached nobody from one that was never sent". So the tests are
 * mostly about what did *not* go out:
 *
 *  - the two skip reasons kept apart, because a segment full of people who never agreed is a list-building
 *    fault and somebody withdrawing in the prepare-to-send gap is the guard working;
 *  - a campaign marked sent whose run never finished, which nothing else notices because the campaign's own
 *    status says it went out;
 *  - a campaign that reached nobody at all.
 */
class CampaignPerformanceReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    private Segment $segment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['campaigns', 'crm', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );

        $this->segment = Segment::create(['name' => 'All customers', 'kind' => 'contact']);
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function campaign(
        string $name = 'February newsletter',
        string $status = Campaign::STATUS_SENT,
        ?string $sentAt = '2027-02-01 09:00:00',
        string $channel = Campaign::CHANNEL_EMAIL,
    ): Campaign {
        $campaign = Campaign::create([
            'name' => $name,
            'channel' => $channel,
            'subject' => 'Hello',
            'template_name' => $channel === Campaign::CHANNEL_WHATSAPP ? 'greeting_v2' : null,
            'segment_id' => $this->segment->getKey(),
            'status' => $status,
            'sent_at' => $sentAt,
        ]);

        // `created_at` places a campaign with no send date, so a test about one has to control it.
        // `forceFill`, not `updateQuietly`: the latter respects `$fillable` and `created_at` is not in it, so
        // the assignment was silently dropped and the row kept today's date — which happened to fall inside
        // the period and made the test pass for the wrong reason until it asserted the date itself.
        if ($sentAt === null) {
            $campaign->forceFill(['created_at' => '2027-02-01 09:00:00'])->saveQuietly();
        }

        return $campaign;
    }

    private function send(Campaign $campaign, string $status, ?string $reason = null, ?string $name = null): CampaignSend
    {
        $contact = Contact::create([
            'name' => $name ?? 'Customer '.fake()->unique()->randomNumber(5),
            'kind' => Contact::KIND_CUSTOMER,
        ]);

        return CampaignSend::create([
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $contact->getKey(),
            'channel' => $campaign->channel,
            'to' => 'someone@example.test',
            'status' => $status,
            'failed_reason' => $reason,
            'sent_at' => $status === CampaignSend::STATUS_SENT ? '2027-02-01 09:05:00' : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('CampaignPerformance', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for CampaignPerformance');

        return $payload;
    }

    private function cell(array $payload, string $column, int $row = 0): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "there is no {$column} column");

        return $payload['rows'][$row][$index];
    }

    // ─────────────────────────────────────────── sends, failures, reasons ──

    /** The counts the plan asks for, per campaign. */
    public function test_a_campaign_reports_its_sends_failures_and_skips(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SENT);
        $this->send($campaign, CampaignSend::STATUS_SENT);
        $this->send($campaign, CampaignSend::STATUS_FAILED, 'Mailbox does not exist');
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);

        $payload = $this->report();

        $this->assertSame('February newsletter', $this->cell($payload, 'Campaign'));
        $this->assertSame('Email', $this->cell($payload, 'Channel'));
        $this->assertSame('4', $this->cell($payload, 'Recipients'));
        $this->assertSame('2', $this->cell($payload, 'Sent'));
        $this->assertSame('1', $this->cell($payload, 'Failed'));
        $this->assertSame('1', $this->cell($payload, 'Skipped'));
        $this->assertSame(2.0, $payload['tiles'][0]['value']);
    }

    /** A zero count is a dash — four count columns of noughts is unreadable. */
    public function test_a_zero_count_shows_a_dash(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SENT);

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Failed'));
        $this->assertSame('—', $this->cell($payload, 'Skipped'));
    }

    /**
     * The reason on the row is the *most common* failure, not the first one found.
     *
     * Failures come from whatever channel sender the company configured, so the reasons are free text from
     * outside this module. Naming the most common one is honest about what is known; a tidy category would be
     * an invention.
     */
    public function test_the_most_common_failure_reason_is_named(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_FAILED, 'Rate limited by the provider');
        $this->send($campaign, CampaignSend::STATUS_FAILED, 'Mailbox does not exist');
        $this->send($campaign, CampaignSend::STATUS_FAILED, 'Mailbox does not exist');

        $payload = $this->report();

        $this->assertSame('Mailbox does not exist', $this->cell($payload, 'Top failure'));
        $this->assertStringContainsString('MOST COMMON FAILURE: MAILBOX DOES NOT EXIST', $payload['note']);
    }

    /**
     * A skipped row's reason is not the campaign's top failure.
     *
     * `failed_reason` carries the *skip* reason too — `prepare()` writes the consent message into it — so a
     * top-failure column that did not filter on `STATUS_FAILED` would report "No consent on record for this
     * channel" as a delivery failure. A surviving mutation is what asked the question.
     */
    public function test_a_skip_reason_is_not_reported_as_a_failure(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SENT);
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Top failure'));
        $this->assertStringNotContainsString('MOST COMMON FAILURE', $payload['note']);
    }

    /**
     * And a failed row carrying a consent message is not counted as a skip.
     *
     * The mirror of the above. Nothing writes this combination today, which is exactly why the guard is worth
     * pinning: the skip split is keyed on the reason, and a status filter is the only thing stopping a
     * delivery failure that happens to mention consent from being counted as one.
     */
    public function test_a_failure_carrying_a_consent_message_is_not_counted_as_a_skip(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_FAILED, CampaignSend::REASON_NO_CONSENT);

        $payload = $this->report();

        $this->assertSame(0.0, $payload['tiles'][1]['value'], 'it failed, it was not skipped');
        $this->assertStringNotContainsString('HAD NEVER AGREED', $payload['note']);
        $this->assertSame('1', $this->cell($payload, 'Failed'));
    }

    /** With no failures there is no reason to name. */
    public function test_a_campaign_with_no_failures_names_no_reason(): void
    {
        $this->send($this->campaign(), CampaignSend::STATUS_SENT);

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Top failure'));
        $this->assertStringNotContainsString('MOST COMMON FAILURE', $payload['note']);
    }

    // ──────────────────────────── the skip split, which is the point ──

    /**
     * Somebody who never agreed is a list-building fault, and the note says so in those terms.
     *
     * It points at the consent register: the segment and the register disagree about who may be reached.
     */
    public function test_a_recipient_who_never_agreed_is_reported_as_a_list_problem(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);

        $payload = $this->report();

        $this->assertSame(1.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString(
            '1 HAD NEVER AGREED, SO THE SEGMENT AND THE CONSENT REGISTER DISAGREE',
            $payload['note'],
        );
    }

    /**
     * Somebody who withdrew in the prepare-to-send gap is the guard working, and is reported as such.
     *
     * `CampaignSender::send()` re-checks consent for exactly this case and calls the gap "exactly when a
     * complaint comes from". Lumping it in with the never-agreed count would report a success as a fault.
     */
    public function test_a_recipient_who_withdrew_before_the_send_is_reported_as_the_guard_working(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_CONSENT_WITHDRAWN);

        $payload = $this->report();

        $this->assertStringContainsString(
            '1 WITHDREW BEFORE THE SEND AND WAS CAUGHT, WHICH IS THE GUARD WORKING',
            $payload['note'],
        );
        $this->assertStringNotContainsString('NEVER AGREED', $payload['note']);
    }

    /** Both reasons at once are counted apart, not merged into one skip figure. */
    public function test_the_two_skip_reasons_are_counted_apart(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_CONSENT_WITHDRAWN);

        $payload = $this->report();

        $this->assertSame(3.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('2 HAD NEVER AGREED', $payload['note']);
        $this->assertStringContainsString('1 WITHDREW BEFORE THE SEND', $payload['note']);
    }

    /**
     * The reasons are matched on the model's constants, not on the sentences.
     *
     * The constants were moved onto `CampaignSend` for this report, because matching a sentence typed in a
     * service is not a contract — a reworded message would silently empty the split.
     */
    public function test_the_split_matches_the_constants_the_sender_writes(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);

        $this->assertStringContainsString('1 HAD NEVER AGREED', $this->report()['note']);

        // A skip with some other reason is still a skip, but it is not one of the two known kinds and is not
        // claimed as either.
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, 'Something else entirely');

        $payload = $this->report();

        $this->assertSame(2.0, $payload['tiles'][1]['value'], 'both are skips');
        $this->assertStringContainsString('1 HAD NEVER AGREED', $payload['note'], 'but only one is that kind');
    }

    // ──────────────────────────── a run that never finished ──

    /**
     * A campaign marked sent with rows still pending never finished its run.
     *
     * `send()` walks every pending row and leaves each one sent or skipped before marking the campaign sent,
     * so a pending row on a sent campaign means the loop stopped part way. Nothing else in the application
     * notices, because the campaign's own status says it went out.
     */
    public function test_a_sent_campaign_with_pending_rows_is_reported_as_unfinished(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SENT);
        $this->send($campaign, CampaignSend::STATUS_PENDING);
        $this->send($campaign, CampaignSend::STATUS_PENDING);

        $payload = $this->report();

        $this->assertSame('Sent · 2 never processed', $this->cell($payload, 'Standing'));
        $this->assertStringContainsString('1 CAMPAIGN MARKED SENT NEVER FINISHED', $payload['note']);
        $this->assertStringContainsString('2 pending', $payload['footer'][7]);

        // Pending rows are recipients. They were prepared and addressed; the run simply never got to them, so
        // leaving them out of the count would understate what the campaign was meant to reach.
        $this->assertSame('3', $this->cell($payload, 'Recipients'));
        $this->assertStringContainsString('3 RECIPIENTS', $payload['note']);
    }

    /** A campaign still in flight is expected to have pending rows, and is not a fault. */
    public function test_a_campaign_in_flight_is_not_reported_as_unfinished(): void
    {
        $campaign = $this->campaign(status: Campaign::STATUS_SENDING, sentAt: null);
        $this->send($campaign, CampaignSend::STATUS_PENDING);

        $payload = $this->report();

        $this->assertSame('In flight', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('NEVER FINISHED', $payload['note']);
    }

    /** Nor is a cancelled one — it was stopped on purpose. */
    public function test_a_cancelled_campaign_is_not_reported_as_unfinished(): void
    {
        $campaign = $this->campaign(status: Campaign::STATUS_CANCELLED, sentAt: null);
        $this->send($campaign, CampaignSend::STATUS_PENDING);

        $payload = $this->report();

        $this->assertSame('Cancelled', $this->cell($payload, 'Standing'));
        $this->assertStringNotContainsString('NEVER FINISHED', $payload['note']);
    }

    /** A clean run says only that it was sent. */
    public function test_a_finished_campaign_stands_clean(): void
    {
        $this->send($this->campaign(), CampaignSend::STATUS_SENT);

        $this->assertSame('Sent', $this->cell($this->report(), 'Standing'));
    }

    /**
     * A campaign that had recipients and reached none of them says so.
     *
     * The case `CampaignSend`'s docblock is about: without this the report cannot distinguish a campaign that
     * reached nobody from one that was never sent.
     */
    public function test_a_campaign_that_reached_nobody_says_so(): void
    {
        $campaign = $this->campaign();
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);
        $this->send($campaign, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);

        $payload = $this->report();

        $this->assertSame('Sent · reached nobody', $this->cell($payload, 'Standing'));
        $this->assertSame(0.0, $payload['tiles'][0]['value']);
    }

    /** A campaign with no recipient rows at all is not "reached nobody" — there was nobody to reach. */
    public function test_a_campaign_with_no_recipients_is_not_called_out_as_reaching_nobody(): void
    {
        $this->campaign();

        $payload = $this->report();

        $this->assertSame('Sent', $this->cell($payload, 'Standing'));
        $this->assertSame('0', $this->cell($payload, 'Recipients'));
    }

    // ───────────────────────────────────── which campaigns appear ──

    /** A draft has no performance to report. */
    public function test_a_draft_campaign_is_not_listed(): void
    {
        $this->campaign(status: Campaign::STATUS_DRAFT, sentAt: null);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO CAMPAIGN WENT OUT IN THIS PERIOD', $payload['note']);
    }

    /** Nor has one that is only scheduled. */
    public function test_a_scheduled_campaign_is_not_listed(): void
    {
        $this->campaign(status: Campaign::STATUS_SCHEDULED, sentAt: null);

        $this->assertSame([], $this->report()['rows']);
    }

    /** The period is the financial year to date, on the send date. */
    public function test_a_campaign_sent_before_the_financial_year_is_not_listed(): void
    {
        $this->campaign(sentAt: '2026-06-15 09:00:00');

        $this->assertSame([], $this->report()['rows']);
    }

    /** And one sent after the date being read has not happened yet, as far as this read is concerned. */
    public function test_a_campaign_sent_after_the_date_being_read_is_not_listed(): void
    {
        $this->campaign(sentAt: '2027-05-01 09:00:00');

        $this->assertSame([], $this->report()['rows']);
    }

    /**
     * A campaign with no send date is placed by when it was created.
     *
     * Without this the unfinished and in-flight runs the report exists to surface would be exactly the ones
     * it could not see, because neither has a `sent_at`.
     */
    public function test_a_campaign_with_no_send_date_is_placed_by_when_it_was_created(): void
    {
        $campaign = $this->campaign(status: Campaign::STATUS_SENDING, sentAt: null);
        $this->send($campaign, CampaignSend::STATUS_PENDING);

        $this->assertCount(1, $this->report()['rows']);
        $this->assertSame('2027-02-01', $this->cell($this->report(), 'Date'));
    }

    /** The most recent campaign is first. */
    public function test_the_most_recent_campaign_is_first(): void
    {
        $this->campaign('Older', sentAt: '2026-08-01 09:00:00');
        $this->campaign('Newer', sentAt: '2027-01-15 09:00:00');

        $payload = $this->report();

        $this->assertSame('Newer', $this->cell($payload, 'Campaign', 0));
        $this->assertSame('Older', $this->cell($payload, 'Campaign', 1));
    }

    // ──────────────────────────────────────────── totals and shape ──

    /** The footer totals every count column. */
    public function test_the_footer_totals_every_count(): void
    {
        $one = $this->campaign('One', sentAt: '2027-01-05 09:00:00');
        $this->send($one, CampaignSend::STATUS_SENT);
        $this->send($one, CampaignSend::STATUS_FAILED, 'Bounced');

        $two = $this->campaign('Two', sentAt: '2027-01-06 09:00:00');
        $this->send($two, CampaignSend::STATUS_SENT);
        $this->send($two, CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::REASON_NO_CONSENT);

        $payload = $this->report();
        $at = fn (string $column): string => $payload['footer'][array_search($column, $payload['columns'], true)];

        $this->assertSame('Total — 2 campaigns', $payload['footer'][0]);
        $this->assertSame('4', $at('Recipients'));
        $this->assertSame('2', $at('Sent'));
        $this->assertSame('1', $at('Failed'));
        $this->assertSame('1', $at('Skipped'));
    }

    /** Nine columns including free-text reasons, so it scrolls rather than being clipped — Phase 0.2. */
    public function test_the_table_is_marked_wide(): void
    {
        $this->send($this->campaign(), CampaignSend::STATUS_SENT);

        $this->assertTrue($this->report()['wide']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->send($this->campaign(), CampaignSend::STATUS_SENT);

        $onThePage = Livewire::test(CampaignPerformance::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame(
            $onThePage,
            app(ReportPaneRenderer::class)->for('CampaignPerformance', self::AS_OF, false, []),
        );
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(CampaignPerformance::canAccess());
    }
}
