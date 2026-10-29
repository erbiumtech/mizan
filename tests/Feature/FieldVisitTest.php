<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Notifications\VisitConfirmationRequest;
use App\Modules\Crm\Services\FieldVisits;
use App\Modules\Employees\Models\Employee;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantSettings;
use App\Support\WhatsApp\CloudApiWhatsAppSender;
use App\Support\WhatsApp\TwilioWhatsAppSender;
use App\Support\WhatsApp\WhatsAppDocument;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Field visits — docs/saas-launch-plan.md §8.5.
 *
 * A visit is a next action with a location and a witness. The claims: check-in
 * and check-out keep the phone's reading; check-out sends the witness a link,
 * by email first and WhatsApp second; the public page has two gates and the
 * link expires; the API serves only the caller's own visits; the reports
 * count what a sales manager asks for.
 */
class FieldVisitTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $user;

    private Employee $rep;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 11:00:00');

        // The public page makes the company current the spatie way; the suite has one database.
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);

        $this->user = User::factory()->create(['status' => 1, 'name' => 'Bilal Rep']);
        $this->actingAs($this->user);
        $this->setCurrentTenant();

        foreach (['crm', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->rep = Employee::create([
            'user_id' => $this->user->getKey(), 'employee_id' => 'EMP-7', 'name' => 'Bilal Rep', 'gender' => 'Male',
            'is_active' => true, 'date_of_joining' => '2021-03-01',
        ]);

        app(TenantSettings::class)->set('crm.field_visits.enabled', true);
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'company_name' => 'Sialkot Sports', 'person_name' => 'Hina', 'email' => 'hina@sialkot.test', 'whatsapp' => '0300 1234567',
        ], $attributes));
    }

    private function visit(?Lead $lead = null, array $attributes = []): FieldVisit
    {
        $lead ??= $this->lead();

        return FieldVisit::create(array_merge([
            'employee_id' => $this->rep->getKey(),
            'subject_type' => ModuleMap::alias(Lead::class),
            'subject_id' => $lead->getKey(),
            'planned_at' => '2026-11-05 10:00:00',
        ], $attributes));
    }

    private function completed(?Lead $lead = null): FieldVisit
    {
        $visit = $this->visit($lead);
        $visits = app(FieldVisits::class);
        $visits->checkIn($visit, ['latitude' => 32.4945, 'longitude' => 74.5229, 'accuracy_m' => 12]);

        return $visits->checkOut($visit->fresh(), ['latitude' => 32.4946, 'longitude' => 74.5230], 'Showed the samples', FieldVisit::OUTCOME_POSITIVE);
    }

    // ───────────────────────────────────────────────────── check-in / out ──

    public function test_check_in_and_out_keep_the_phone_reading_and_send_the_witness_an_email(): void
    {
        Notification::fake();

        $visit = $this->completed();

        $this->assertSame('2026-11-05 11:00:00', $visit->checked_in_at->toDateTimeString());
        $this->assertSame(32.4945, $visit->in_latitude);
        $this->assertSame(12, (int) $visit->in_accuracy_m);
        $this->assertSame(32.4946, $visit->out_latitude);
        $this->assertSame(FieldVisit::OUTCOME_POSITIVE, $visit->outcome);
        $this->assertSame('https://www.google.com/maps?q=32.4945,74.5229', $visit->mapLink());
        $this->assertSame('hina@sialkot.test', $visit->confirmation_sent_to);
        $this->assertNull($visit->confirmed_at);

        Notification::assertSentOnDemand(
            VisitConfirmationRequest::class,
            fn (VisitConfirmationRequest $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'hina@sialkot.test'
                && $n->visitorName === 'Bilal Rep'
                && str_contains($n->url, '/visits/confirm/'.$this->tenant->slug.'/')
                && str_contains($n->url, 'signature='),
        );
    }

    public function test_whatsapp_carries_the_link_when_the_contact_has_no_email(): void
    {
        $sent = [];
        $this->swap(WhatsAppSender::class, new class($sent) implements WhatsAppSender
        {
            public function __construct(public array &$sent) {}

            public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
            {
                throw new \LogicException('no documents here');
            }

            public function sendText(string $to, string $text): string
            {
                $this->sent[] = compact('to', 'text');

                return 'wamid.test';
            }
        });

        $visit = $this->completed($this->lead(['email' => null]));

        $this->assertCount(1, $sent);
        $this->assertSame('923001234567', $sent[0]['to'], 'E.164 without the plus, as the senders expect');
        $this->assertStringContainsString('Bilal Rep', $sent[0]['text']);
        $this->assertStringContainsString('/visits/confirm/', $sent[0]['text']);
        $this->assertSame('whatsapp:923001234567', $visit->confirmation_sent_to);
    }

    public function test_a_failed_send_does_not_undo_the_check_out(): void
    {
        $this->swap(WhatsAppSender::class, new class implements WhatsAppSender
        {
            public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
            {
                throw new \LogicException('no documents here');
            }

            public function sendText(string $to, string $text): string
            {
                throw new \App\Support\WhatsApp\WhatsAppException('Outside the 24-hour window');
            }
        });

        $visit = $this->completed($this->lead(['email' => null]));

        $this->assertNotNull($visit->checked_out_at);
        $this->assertNull($visit->confirmation_sent_to);
    }

    public function test_nothing_is_sent_when_links_are_switched_off(): void
    {
        Notification::fake();
        app(TenantSettings::class)->set('crm.field_visits.enabled', false);

        $visit = $this->completed();

        $this->assertNull($visit->confirmation_sent_to);
        Notification::assertNothingSent();
    }

    public function test_check_out_needs_a_check_in_and_happens_once(): void
    {
        $visits = app(FieldVisits::class);
        $visit = $this->visit();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Check in before');
        $visits->checkOut($visit, null);
    }

    // ───────────────────────────────────────────────────── confirmation page ──

    public function test_the_witness_confirms_from_the_signed_link(): void
    {
        Notification::fake();
        $visit = $this->completed();
        $url = app(FieldVisits::class)->confirmationUrl($visit, $this->tenant);
        Company::forgetCurrent();

        $this->get($url)->assertOk()->assertSee('Bilal Rep')->assertSee('Thursday 05 November 2026')->assertSee('not a signature');

        $this->post($url, ['name' => 'Hina Malik'])->assertOk()->assertSee('Thank you, Hina Malik');

        $this->tenant->makeCurrent();
        $fresh = $visit->fresh();
        $this->assertSame('Hina Malik', $fresh->confirmed_name);
        $this->assertNotNull($fresh->confirmed_at);
    }

    public function test_the_page_has_two_gates_and_the_link_expires(): void
    {
        Notification::fake();
        $visit = $this->completed();
        $url = app(FieldVisits::class)->confirmationUrl($visit, $this->tenant);
        Company::forgetCurrent();

        // Gate one: the signature.
        $this->get(str_replace('signature=', 'signature=0', $url))->assertNotFound();

        // Gate two: the company's switch, which closes links already sent.
        $this->tenant->makeCurrent();
        app(TenantSettings::class)->set('crm.field_visits.enabled', false);
        Company::forgetCurrent();
        $this->get($url)->assertNotFound();

        // And the clock: a day, no more.
        $this->tenant->makeCurrent();
        app(TenantSettings::class)->set('crm.field_visits.enabled', true);
        Company::forgetCurrent();
        Carbon::setTestNow('2026-11-06 11:00:01');
        $this->get($url)->assertNotFound();
    }

    // ───────────────────────────────────────────────────────────────── API ──

    public function test_the_api_serves_the_callers_own_visits_only(): void
    {
        Notification::fake();
        $mine = $this->visit();

        $other = User::factory()->create(['status' => 1]);
        $colleague = Employee::create(['user_id' => $other->getKey(), 'employee_id' => 'EMP-8', 'name' => 'Sana', 'gender' => 'Female', 'is_active' => true, 'date_of_joining' => '2022-01-01']);
        $theirs = $this->visit(null, ['employee_id' => $colleague->getKey()]);

        Sanctum::actingAs($this->user, ['api']);

        $this->getJson('/api/v1/my-field-visits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->getKey())
            ->assertJsonPath('data.0.subject', 'Sialkot Sports — Hina');

        $this->postJson("/api/v1/field-visits/{$theirs->getKey()}/check-in", ['latitude' => 1, 'longitude' => 1])->assertNotFound();

        $this->postJson("/api/v1/field-visits/{$mine->getKey()}/check-in", ['latitude' => 32.49, 'longitude' => 74.52, 'accuracy_m' => 30])
            ->assertOk()
            ->assertJsonPath('data.checked_in_at', Carbon::now()->toIso8601String())
            ->assertJsonPath('data.map', 'https://www.google.com/maps?q=32.49,74.52');

        $this->postJson("/api/v1/field-visits/{$mine->getKey()}/check-out", ['outcome' => 'follow_up', 'notes' => 'Send the quote'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'follow_up');

        $this->postJson("/api/v1/field-visits/{$mine->getKey()}/check-out", [])->assertStatus(422);
    }

    // ───────────────────────────────────────────────────────────── reports ──

    public function test_the_reports_count_visits_per_rep_and_list_the_unconfirmed(): void
    {
        Notification::fake();
        $visits = app(FieldVisits::class);

        $a = $this->completed();
        $b = $this->completed();
        $visits->confirm($b, 'Hina');
        $this->visit(); // planned, not done: counts nowhere

        $perRep = ReportRenderers::render('VisitsPerRep', '2026-11-30', false, []);
        $this->assertCount(1, $perRep['rows']);
        $this->assertSame([2, 1, '50%'], array_slice($perRep['rows'][0], 1, 3));
        $this->assertSame(2, $perRep['tiles'][0]['value']);

        $unconfirmed = ReportRenderers::render('UnconfirmedVisits', '2026-11-30', false, []);
        $this->assertCount(1, $unconfirmed['rows']);
        $this->assertSame('hina@sialkot.test', $unconfirmed['rows'][0][4]);
        $this->assertSame(1, $unconfirmed['tiles'][0]['value']);
        $this->assertSame($a->getKey(), FieldVisit::query()->unconfirmed()->sole()->getKey());
    }

    // ──────────────────────────────────────────────────────── the senders ──

    public function test_the_cloud_api_sender_posts_a_text_message(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        $id = (new CloudApiWhatsAppSender('PHONE-ID', 'TOKEN'))->sendText('923001234567', 'Please confirm: https://x.test/v');

        $this->assertSame('wamid.123', $id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/PHONE-ID/messages')
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Please confirm: https://x.test/v'
            && $request['text']['preview_url'] === true);
    }

    public function test_the_twilio_sender_posts_a_body_and_no_media(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'])]);

        $id = (new TwilioWhatsAppSender('AC1', 'TOKEN', '14155238886', contentSid: 'HX-ignored-for-text'))->sendText('923001234567', 'Please confirm');

        $this->assertSame('SM123', $id);
        Http::assertSent(fn ($request) => $request['Body'] === 'Please confirm'
            && $request['To'] === 'whatsapp:+923001234567'
            && ! isset($request['MediaUrl'])
            && ! isset($request['ContentSid']));
    }
}
