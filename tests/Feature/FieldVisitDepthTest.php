<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Models\FieldVisitPlace;
use App\Modules\Crm\Models\FieldVisitRefusal;
use App\Modules\Crm\Models\FieldVisitStaff;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\FieldVisits;
use App\Modules\Employees\Models\Employee;
use App\Support\ModuleMap;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Field visits, judged — docs/saas-launch-plan.md §14.10.
 *
 * The coordinates have been recorded since §8.5 and nothing looked at them. What is
 * tested here is the judging, and the distinctions in it that a refactor could quietly
 * flatten: that "nothing judged it" is not "away", that a vague reading is neither,
 * and that a refusal is recorded rather than thrown away.
 */
class FieldVisitDepthTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** The client's front door. */
    private const PIN = ['latitude' => 32.4945, 'longitude' => 74.5229];

    private User $user;

    private Employee $rep;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 11:00:00');
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
            'user_id' => $this->user->getKey(), 'employee_id' => 'EMP-7', 'name' => 'Bilal Rep',
            'gender' => 'Male', 'is_active' => true, 'date_of_joining' => '2021-03-01',
        ]);

        $this->lead = Lead::create(['company_name' => 'Sialkot Sports', 'person_name' => 'Hina', 'email' => 'hina@sialkot.test']);
    }

    private function visit(): FieldVisit
    {
        return FieldVisit::create([
            'employee_id' => $this->rep->getKey(),
            'subject_type' => ModuleMap::alias(Lead::class),
            'subject_id' => $this->lead->getKey(),
            'planned_at' => '2026-11-05 10:00:00',
        ]);
    }

    private function pin(int $radius = 150): FieldVisitPlace
    {
        return FieldVisitPlace::create([
            'subject_type' => ModuleMap::alias(Lead::class),
            'subject_id' => $this->lead->getKey(),
            ...self::PIN,
            'radius_m' => $radius,
        ]);
    }

    /** A point roughly `$metres` north of the pin. */
    private function northOfPin(float $metres): array
    {
        return ['latitude' => self::PIN['latitude'] + ($metres / 111_320), 'longitude' => self::PIN['longitude']];
    }

    // ----------------------------------------------------------- the judgement

    public function test_nothing_is_judged_until_somebody_has_pinned_the_place(): void
    {
        $visit = app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(5000), 'accuracy_m' => 10]);

        $this->assertNull(
            $visit->in_verdict,
            'five kilometres away, and still unjudged — "we never looked" is not "they were not there"',
        );
        $this->assertNull($visit->in_distance_m);
    }

    public function test_a_check_in_at_the_place_is_judged_so(): void
    {
        $this->pin();

        $visit = app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(40), 'accuracy_m' => 10]);

        $this->assertSame(FieldVisit::VERDICT_HERE, $visit->in_verdict);
        $this->assertEqualsWithDelta(40, $visit->in_distance_m, 3);
    }

    public function test_a_check_in_outside_the_radius_is_away_with_the_distance(): void
    {
        $this->pin(100);

        $visit = app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(400), 'accuracy_m' => 10]);

        $this->assertSame(FieldVisit::VERDICT_AWAY, $visit->in_verdict);
        $this->assertEqualsWithDelta(400, $visit->in_distance_m, 5);
    }

    public function test_a_reading_vaguer_than_the_limit_is_neither_here_nor_away(): void
    {
        $this->pin(100);

        // Four hundred metres out, but the phone admits to being half a kilometre wrong.
        // Calling that "away" would punish somebody for being indoors.
        $visit = app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(400), 'accuracy_m' => 500]);

        $this->assertSame(FieldVisit::VERDICT_UNVERIFIED, $visit->in_verdict);
    }

    public function test_the_accuracy_limit_is_the_companys_to_set(): void
    {
        $this->pin(100);
        app(TenantSettings::class)->set('crm.field_visits.accuracy_limit_m', 800);

        $visit = app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(400), 'accuracy_m' => 500]);

        $this->assertSame(FieldVisit::VERDICT_AWAY, $visit->in_verdict, 'the same reading, now inside the limit');
    }

    public function test_the_check_can_be_switched_off_entirely(): void
    {
        $this->pin(100);
        app(TenantSettings::class)->set('crm.field_visits.geofence_mode', FieldVisits::MODE_OFF);

        $visit = app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(4000), 'accuracy_m' => 10]);

        $this->assertNull($visit->in_verdict, 'off records the position and judges nothing, as it always did');
        $this->assertNotNull($visit->in_latitude);
    }

    // ------------------------------------------------------------- refusing

    public function test_flagging_never_turns_anybody_away(): void
    {
        $this->pin(100);

        $visit = app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(900), 'accuracy_m' => 10]);

        $this->assertTrue($visit->isCheckedIn(), 'flag is the default, and it flags rather than refuses');
        $this->assertSame(0, FieldVisitRefusal::count());
    }

    public function test_refusing_turns_the_check_in_away_and_records_why(): void
    {
        $this->pin(100);
        app(TenantSettings::class)->set('crm.field_visits.geofence_mode', FieldVisits::MODE_REFUSE);

        $visit = $this->visit();

        try {
            app(FieldVisits::class)->checkIn($visit, [...$this->northOfPin(900), 'accuracy_m' => 10]);
            $this->fail('a check-in away from the place must be refused in refuse mode');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('from the place', $e->getMessage());
        }

        $this->assertFalse($visit->refresh()->isCheckedIn());

        $refusal = FieldVisitRefusal::sole();
        $this->assertSame(FieldVisitRefusal::REASON_AWAY, $refusal->reason);
        $this->assertSame($this->rep->getKey(), $refusal->employee_id);
        $this->assertEqualsWithDelta(900, $refusal->distance_m, 10);
        $this->assertSame(10, $refusal->accuracy_m, 'the reading is kept, which is what separates the two problems');
    }

    public function test_a_vague_reading_is_refused_as_vague_rather_than_as_away(): void
    {
        $this->pin(100);
        app(TenantSettings::class)->set('crm.field_visits.geofence_mode', FieldVisits::MODE_REFUSE);

        try {
            app(FieldVisits::class)->checkIn($this->visit(), [...$this->northOfPin(900), 'accuracy_m' => 2000]);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('accurately enough', $e->getMessage());
        }

        $this->assertSame(FieldVisitRefusal::REASON_INACCURATE, FieldVisitRefusal::sole()->reason);
    }

    // ------------------------------------------------------------- enrolment

    public function test_enrolment_does_nothing_until_it_is_switched_on(): void
    {
        $this->assertTrue(FieldVisitStaff::allows($this->rep->getKey()), 'off, anybody may be sent');

        app(TenantSettings::class)->set('crm.field_visits.enrolment_required', true);

        $this->assertFalse(FieldVisitStaff::allows($this->rep->getKey()));

        FieldVisitStaff::create(['employee_id' => $this->rep->getKey()]);

        $this->assertTrue(FieldVisitStaff::allows($this->rep->getKey()));
    }

    public function test_somebody_not_enrolled_is_refused_and_recorded(): void
    {
        app(TenantSettings::class)->set('crm.field_visits.enrolment_required', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not enrolled');

        try {
            app(FieldVisits::class)->checkIn($this->visit(), [...self::PIN, 'accuracy_m' => 5]);
        } finally {
            $this->assertSame(FieldVisitRefusal::REASON_NOT_ENROLLED, FieldVisitRefusal::sole()->reason);
        }
    }

    // ---------------------------------------------------------------- rating

    public function test_the_client_may_rate_the_visit_and_may_decline_to(): void
    {
        $visits = app(FieldVisits::class);

        $rated = $this->visit();
        $visits->checkIn($rated, null);
        $visits->checkOut($rated, null, null, FieldVisit::OUTCOME_POSITIVE);
        $visits->confirm($rated, 'Hina', 5, 'Arrived on time.');

        $this->assertSame(5, $rated->refresh()->rating);
        $this->assertSame('Excellent', $rated->ratingLabel());
        $this->assertSame('Arrived on time.', $rated->rating_comment);

        $unrated = $this->visit();
        $visits->checkIn($unrated, null);
        $visits->checkOut($unrated, null, null, FieldVisit::OUTCOME_POSITIVE);
        $visits->confirm($unrated, 'Hina');

        $this->assertNull($unrated->refresh()->rating, 'optional, and it stays optional');
        $this->assertTrue($unrated->isConfirmed());
    }

    public function test_a_rating_outside_the_scale_is_dropped_rather_than_stored(): void
    {
        $visits = app(FieldVisits::class);
        $visit = $this->visit();
        $visits->checkIn($visit, null);
        $visits->checkOut($visit, null, null, FieldVisit::OUTCOME_POSITIVE);

        $visits->confirm($visit, 'Hina', 9);

        $this->assertNull($visit->refresh()->rating);
        $this->assertTrue($visit->isConfirmed(), 'and the confirmation still counts');
    }
}
