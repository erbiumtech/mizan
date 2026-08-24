<?php

namespace Tests\Feature;

use App\Modules\Campaigns\Filament\Pages\ConsentRegister;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Models\Contact;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The consent register — `docs/reports-expansion-plan.md` Phase 3.10.
 *
 * The plan's own framing is the specification: "compliance evidence, not marketing statistics". So there is
 * nothing here about opt-in rates, and the tests are about the three things evidence has to survive:
 *
 *  - **the state is derived, never counted** — `consents` has no unique key on subject and channel, so a
 *    subject who opted in, out and in again has three rows and exactly one current state;
 *  - **it resolves the tie the same way `Consent::permits()` does** — most recent `recorded_at`, then highest
 *    `id`. A report that broke the tie the other way would state a permission the sender will not act on;
 *  - **a grant with no source is not evidence of anything**, which is the migration's own wording and the
 *    report's headline finding.
 *
 * And it is a true as-at: the latest row *on or before* the date being read, so "what did we have permission
 * for on 30 June" has an answer.
 */
class ConsentRegisterReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    private User $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = User::factory()->create(['status' => 1, 'name' => 'Sana Iqbal']);
        $this->actingAs($this->recorder);
        $this->setCurrentTenant();

        foreach (['campaigns', 'crm', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function contact(string $name = 'Karachi Textiles'): Contact
    {
        return Contact::create(['name' => $name, 'kind' => Contact::KIND_CUSTOMER]);
    }

    private function lead(string $name = 'Lahore Mills'): Lead
    {
        return Lead::create(['company_name' => $name, 'contact_name' => 'Imran Shah']);
    }

    /**
     * A consent row written directly, so a test can control `recorded_at`, the source and the recorder.
     *
     * `Consent::grant()` and `revoke()` are used too, in the tests about how the model itself behaves — but
     * they stamp `recorded_at` with `now()`, which cannot express a history.
     */
    private function consent(
        Contact|Lead $subject,
        string $state,
        string $recordedAt,
        ?string $source = 'Website form',
        string $channel = Consent::CHANNEL_EMAIL,
        ?int $recordedBy = -1,
    ): Consent {
        $consent = Consent::create([
            'subject_type' => \App\Support\ModuleMap::alias($subject::class),
            'subject_id' => $subject->getKey(),
            'channel' => $channel,
            'state' => $state,
            'source' => $source,
            'recorded_at' => $recordedAt,
            'recorded_by' => $recordedBy === -1 ? $this->recorder->getKey() : $recordedBy,
        ]);

        // `Consent::booted()` does `recorded_by ??= auth()->id()`, so passing null through `create()` gets
        // the acting user stamped on it anyway. Nulling the column afterwards is not a workaround: it is the
        // only way this row ever arises, namely written outside a request — an import, a seeder, a console
        // command — which is exactly the case the report is reporting.
        if ($recordedBy === null) {
            $consent->updateQuietly(['recorded_by' => null]);
        }

        return $consent;
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('ConsentRegister', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for ConsentRegister');

        return $payload;
    }

    private function cell(array $payload, string $column, int $row = 0): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "there is no {$column} column");

        return $payload['rows'][$row][$index];
    }

    // ──────────────────────────────────────────── the state is derived ──

    /** A single grant is one row on the register, with the evidence beside it. */
    public function test_a_grant_is_listed_with_its_source_and_date(): void
    {
        $this->consent($this->contact(), Consent::STATE_GRANTED, '2026-09-01 10:00:00', 'Website form');

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertSame('Contact · Karachi Textiles', $this->cell($payload, 'Subject'));
        $this->assertSame('Email', $this->cell($payload, 'Channel'));
        $this->assertSame('Granted', $this->cell($payload, 'State'));
        $this->assertSame('Website form', $this->cell($payload, 'Source'));
        $this->assertSame('2026-09-01', $this->cell($payload, 'Recorded'));
        $this->assertSame('Sana Iqbal', $this->cell($payload, 'Recorded by'));
    }

    /**
     * Opting in, out and in again is three rows and one current state.
     *
     * The reason the table has no unique key on subject and channel. A register that counted rows would
     * report this subject three times; one that read a cached flag would have thrown the history away.
     */
    public function test_a_subject_who_changed_their_mind_appears_once_with_the_latest_state(): void
    {
        $contact = $this->contact();
        $this->consent($contact, Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($contact, Consent::STATE_REVOKED, '2026-09-01 10:00:00');
        $this->consent($contact, Consent::STATE_GRANTED, '2026-10-01 10:00:00');

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertSame('Granted', $this->cell($payload, 'State'));
        $this->assertSame('2026-10-01', $this->cell($payload, 'Recorded'));
        $this->assertSame('3', $this->cell($payload, 'Changes'), 'the trail is the evidence');
    }

    /** A revocation after a grant leaves the subject revoked. */
    public function test_a_revocation_is_the_current_state(): void
    {
        $contact = $this->contact();
        $this->consent($contact, Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($contact, Consent::STATE_REVOKED, '2026-09-01 10:00:00');

        $payload = $this->report();

        $this->assertSame('Revoked', $this->cell($payload, 'State'));
        $this->assertSame(0.0, $payload['tiles'][0]['value'], 'nothing is granted');
        $this->assertStringContainsString('0 GRANTED, 1 REVOKED', $payload['note']);
    }

    /**
     * The register agrees with `Consent::permits()`, which is the thing that actually gates a send.
     *
     * Asserted against the model rather than against a literal. If the two ever disagreed, this report would
     * be stating a permission the sender refuses to act on — or worse, the reverse.
     */
    public function test_the_register_agrees_with_what_permits_says(): void
    {
        $granted = $this->contact('Granted Co');
        $this->consent($granted, Consent::STATE_GRANTED, '2026-08-01 10:00:00');

        $revoked = $this->contact('Revoked Co');
        $this->consent($revoked, Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($revoked, Consent::STATE_REVOKED, '2026-09-01 10:00:00');

        $this->assertTrue(Consent::permits($granted, Consent::CHANNEL_EMAIL));
        $this->assertFalse(Consent::permits($revoked, Consent::CHANNEL_EMAIL));

        $states = [];

        foreach ($this->report()['rows'] as $row) {
            $states[$row[0]] = $row[2];
        }

        $this->assertSame('Granted', $states['Contact · Granted Co']);
        $this->assertSame('Revoked', $states['Contact · Revoked Co']);
    }

    /**
     * Two rows with the same timestamp are broken on the id, the way `permits()` breaks them.
     *
     * A bulk import can stamp a whole file with one timestamp, so this is not contrived. Whichever row the
     * sender would act on is the one the register has to show.
     */
    public function test_rows_sharing_a_timestamp_are_broken_on_the_id_like_permits_does(): void
    {
        $contact = $this->contact();
        $this->consent($contact, Consent::STATE_GRANTED, '2026-09-01 10:00:00');
        $this->consent($contact, Consent::STATE_REVOKED, '2026-09-01 10:00:00');

        $this->assertFalse(Consent::permits($contact, Consent::CHANNEL_EMAIL), 'the later id wins');
        $this->assertSame('Revoked', $this->cell($this->report(), 'State'));
    }

    /** Each channel is its own permission — agreeing to email is not agreeing to WhatsApp. */
    public function test_each_channel_is_its_own_row(): void
    {
        $contact = $this->contact();
        $this->consent($contact, Consent::STATE_GRANTED, '2026-08-01 10:00:00', channel: Consent::CHANNEL_EMAIL);
        $this->consent($contact, Consent::STATE_REVOKED, '2026-08-01 10:00:00', channel: Consent::CHANNEL_WHATSAPP);

        $payload = $this->report();
        $states = [];

        foreach ($payload['rows'] as $row) {
            $states[$row[1]] = $row[2];
        }

        $this->assertCount(2, $payload['rows']);
        $this->assertSame('Granted', $states['Email']);
        $this->assertSame('Revoked', $states['Whatsapp']);
    }

    /** A lead and a contact are named apart — they can share a name, and evidence about the wrong person is worse than none. */
    public function test_a_lead_and_a_contact_are_distinguished(): void
    {
        $this->consent($this->contact('Acme'), Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($this->lead('Acme'), Consent::STATE_GRANTED, '2026-08-02 10:00:00');

        $subjects = array_map(fn (array $row): string => $row[0], $this->report()['rows']);

        $this->assertContains('Contact · Acme', $subjects);
        $this->assertSame(2, count($subjects));
        $this->assertTrue(
            (bool) array_filter($subjects, fn (string $s): bool => str_starts_with($s, 'Lead · ')),
            'the lead is named as a lead',
        );
    }

    // ────────────────────────────────────────────────── the as-at ──

    /**
     * The register is read as at a date, so a later change does not rewrite the past.
     *
     * "What did we have permission for on 30 June" is the question a complaint asks, and it cannot be
     * answered by a report that only knows about now.
     */
    public function test_the_state_is_read_as_at_the_date_not_as_at_today(): void
    {
        $contact = $this->contact();
        $this->consent($contact, Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($contact, Consent::STATE_REVOKED, '2026-12-01 10:00:00');

        $this->assertSame('Granted', $this->cell($this->report('2026-09-30'), 'State'));
        $this->assertSame('Revoked', $this->cell($this->report(), 'State'));
    }

    /** The change count is as-at too — it counts the trail up to the date, not beyond it. */
    public function test_the_change_count_is_also_as_at_the_date(): void
    {
        $contact = $this->contact();
        $this->consent($contact, Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($contact, Consent::STATE_REVOKED, '2026-12-01 10:00:00');

        $this->assertSame('1', $this->cell($this->report('2026-09-30'), 'Changes'));
        $this->assertSame('2', $this->cell($this->report(), 'Changes'));
    }

    /**
     * A subject whose only rows come later is absent, not shown as revoked.
     *
     * There was nothing on the register then, and `permits()` is explicit that no row means no. Showing them
     * as revoked would claim a record that did not exist.
     */
    public function test_a_subject_recorded_after_the_date_is_absent(): void
    {
        $this->consent($this->contact(), Consent::STATE_GRANTED, '2027-03-01 10:00:00');

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertStringContainsString('NOBODY MAY BE CONTACTED', $payload['note']);
    }

    /** An empty register says nobody may be contacted, rather than "nothing to show". */
    public function test_an_empty_register_says_nobody_may_be_contacted(): void
    {
        $payload = $this->report();

        $this->assertSame(
            'NOBODY HAS BEEN ASKED FOR CONSENT ON ANY CHANNEL, SO NOBODY MAY BE CONTACTED',
            $payload['note'],
        );
    }

    // ───────────────────────────────── a permission with no evidence ──

    /**
     * A grant with no source is the report's headline finding.
     *
     * The migration says why in as many words: "'they agreed' is worth nothing without 'and here is how'". On
     * every other screen this permission is indistinguishable from a defensible one.
     */
    public function test_a_grant_with_no_source_is_called_out(): void
    {
        $this->consent($this->contact(), Consent::STATE_GRANTED, '2026-09-01 10:00:00', source: null);

        $payload = $this->report();

        $this->assertSame('Not recorded', $this->cell($payload, 'Source'));
        $this->assertSame(1.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString(
            '1 GRANTED WITH NO SOURCE, WHICH IS NOT EVIDENCE OF ANYTHING',
            $payload['note'],
        );
    }

    /** An empty source is as absent as a null — both are what a blank form field leaves behind. */
    public function test_an_empty_source_counts_as_no_source(): void
    {
        $this->consent($this->contact(), Consent::STATE_GRANTED, '2026-09-01 10:00:00', source: '   ');

        $payload = $this->report();

        $this->assertSame('Not recorded', $this->cell($payload, 'Source'));
        $this->assertSame(1.0, $payload['tiles'][1]['value']);
    }

    /** A sourced grant is not reported as unevidenced, and the note says the register is clean. */
    public function test_a_sourced_grant_is_not_called_out(): void
    {
        $this->consent($this->contact(), Consent::STATE_GRANTED, '2026-09-01 10:00:00', 'Signed form');

        $payload = $this->report();

        $this->assertSame(0.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('EVERY PERMISSION HAS A SOURCE RECORDED AGAINST IT', $payload['note']);
    }

    /**
     * A revocation with no source is not a finding, and the asymmetry is deliberate.
     *
     * Removing somebody from a list needs no justification; only a permission has to be defended. Counting
     * sourceless revocations would bury the grants that matter under rows nobody needs to act on.
     */
    public function test_a_revocation_with_no_source_is_not_a_finding(): void
    {
        $this->consent($this->contact(), Consent::STATE_REVOKED, '2026-09-01 10:00:00', source: null);

        $payload = $this->report();

        $this->assertSame(0.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('EVERY PERMISSION HAS A SOURCE RECORDED AGAINST IT', $payload['note']);
    }

    /** A grant with nobody recorded against it is reported too, below the sourceless ones. */
    public function test_a_grant_with_no_recorder_is_called_out(): void
    {
        $this->consent($this->contact(), Consent::STATE_GRANTED, '2026-09-01 10:00:00', recordedBy: null);

        $payload = $this->report();

        $this->assertSame('Not recorded', $this->cell($payload, 'Recorded by'));
        $this->assertStringContainsString('1 GRANTED WITH NOBODY RECORDED AGAINST IT', $payload['note']);
    }

    /** An unevidenced grant sorts above a properly evidenced one — it is the row to fix. */
    public function test_an_unevidenced_grant_sorts_first(): void
    {
        // The sourced grant is newer, so an ordering by date alone would put it first.
        $this->consent($this->contact('Sourced Co'), Consent::STATE_GRANTED, '2026-12-01 10:00:00', 'Signed form');
        $this->consent($this->contact('Bare Co'), Consent::STATE_GRANTED, '2026-08-01 10:00:00', source: null);

        $this->assertSame('Contact · Bare Co', $this->cell($this->report(), 'Subject', 0));
    }

    /** And a revocation sorts last: it is the safe state, with nothing at risk. */
    public function test_a_revocation_sorts_below_a_grant(): void
    {
        $this->consent($this->contact('Revoked Co'), Consent::STATE_REVOKED, '2026-12-01 10:00:00');
        $this->consent($this->contact('Granted Co'), Consent::STATE_GRANTED, '2026-08-01 10:00:00');

        $payload = $this->report();

        $this->assertSame('Contact · Granted Co', $this->cell($payload, 'Subject', 0));
        $this->assertSame('Contact · Revoked Co', $this->cell($payload, 'Subject', 1));
    }

    /** Within a group, the most recent record is first. */
    public function test_the_most_recent_record_is_first_within_a_group(): void
    {
        $this->consent($this->contact('Older Co'), Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($this->contact('Newer Co'), Consent::STATE_GRANTED, '2026-12-01 10:00:00');

        $payload = $this->report();

        $this->assertSame('Contact · Newer Co', $this->cell($payload, 'Subject', 0));
        $this->assertSame('Contact · Older Co', $this->cell($payload, 'Subject', 1));
    }

    // ─────────────────────────────────────────── it is not marketing ──

    /**
     * There is no opt-in rate anywhere on this report, and that is the plan's instruction.
     *
     * "Compliance evidence, not marketing statistics." A percentage invites a target, and the moment consent
     * has a target somebody starts managing the number instead of the record.
     */
    public function test_the_report_states_no_rate_or_percentage(): void
    {
        $this->consent($this->contact('A'), Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($this->contact('B'), Consent::STATE_REVOKED, '2026-08-01 10:00:00');

        $payload = $this->report();

        $this->assertStringNotContainsString('%', $payload['note']);

        foreach ($payload['tiles'] as $tile) {
            $this->assertStringNotContainsString('%', $tile['label']);
            $this->assertStringNotContainsString('RATE', $tile['label']);
        }
    }

    /** The footer counts the pairs, the grants and the whole trail. */
    public function test_the_footer_counts_the_pairs_and_the_trail(): void
    {
        $contact = $this->contact();
        $this->consent($contact, Consent::STATE_GRANTED, '2026-08-01 10:00:00');
        $this->consent($contact, Consent::STATE_REVOKED, '2026-09-01 10:00:00');
        $this->consent($this->lead(), Consent::STATE_GRANTED, '2026-08-01 10:00:00');

        $payload = $this->report();

        $this->assertSame('Total — 2 subject/channel pairs', $payload['footer'][0]);
        $this->assertSame('1 granted', $payload['footer'][2]);
        $this->assertSame('3', $payload['footer'][6], 'three rows of history behind two pairs');
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->consent($this->contact(), Consent::STATE_GRANTED, '2026-09-01 10:00:00');

        $onThePage = Livewire::test(ConsentRegister::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame(
            $onThePage,
            app(ReportPaneRenderer::class)->for('ConsentRegister', self::AS_OF, false, []),
        );
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(ConsentRegister::canAccess());
    }
}
