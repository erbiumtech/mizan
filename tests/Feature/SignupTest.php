<?php

namespace Tests\Feature;

use App\Modules\Core\Jobs\ProvisionCompany;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Signup;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use App\Multitenancy\TenantAwareJobs;
use App\Notifications\SignupVerification;
use App\Support\CompanyProfiles;
use App\Support\Plans;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The signup door — docs/saas-launch-plan.md §5.
 *
 * Four rules, each pinned: nothing is provisioned until the mailbox is proven;
 * an existing email is refused and attaches nothing (the 2026-10-26 audit's
 * finding 1, at the new door); a throttled or honeypot submission answers
 * exactly like a success; and the job that does the provisioning serves the
 * landlord, so the tenant-aware queue guard lets it through.
 */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    private const FORM = [
        'name' => 'Ayesha Khan',
        'email' => 'ayesha@example.test',
        'password' => 'correct horse battery',
        'company' => 'Chai Khana',
        'profile' => 'restaurant',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('signup:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach (Company::all() as $company) {
            if ($company->database && File::exists($company->database)) {
                File::delete($company->database);
            }
        }

        parent::tearDown();
    }

    public function test_the_form_offers_the_business_profiles(): void
    {
        $this->get('/signup')
            ->assertOk()
            ->assertSee('Create your company')
            ->assertSee(CompanyProfiles::label('restaurant'))
            ->assertDontSee(CompanyProfiles::label('personal'));
    }

    public function test_submitting_creates_an_unverified_user_and_provisions_nothing(): void
    {
        Notification::fake();
        Bus::fake();

        $this->post('/signup', self::FORM)
            ->assertOk()
            ->assertViewIs('signup.sent')
            ->assertSee('Chai Khana');

        $user = User::where('email', 'ayesha@example.test')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertSame(1, (int) $user->status);
        $this->assertFalse((bool) $user->is_super_admin);

        $signup = Signup::where('user_id', $user->getKey())->firstOrFail();
        $this->assertSame('Chai Khana', $signup->company_name);
        $this->assertSame('restaurant', $signup->profile);
        $this->assertTrue($signup->isPending());

        // The whole point: one mail, no company, no job.
        Notification::assertSentTo($user, SignupVerification::class);
        Bus::assertNothingDispatched();
        $this->assertSame(0, Company::count());
        $this->assertSame(0, $user->companies()->count());
    }

    public function test_the_verification_link_dispatches_the_job_once_and_signs_the_person_in(): void
    {
        Notification::fake();
        Bus::fake();

        $this->post('/signup', self::FORM);
        $user = User::where('email', 'ayesha@example.test')->firstOrFail();

        $this->get($this->linkFor($user))
            ->assertRedirect(route('signup.ready'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Bus::assertDispatchedTimes(ProvisionCompany::class, 1);

        // A second click — or a mail client prefetching the link — provisions
        // nothing more.
        $this->get($this->linkFor($user))->assertRedirect(route('signup.ready'));
        Bus::assertDispatchedTimes(ProvisionCompany::class, 1);
    }

    public function test_a_tampered_or_mismatched_link_is_refused(): void
    {
        Notification::fake();

        $this->post('/signup', self::FORM);
        $user = User::where('email', 'ayesha@example.test')->firstOrFail();

        // Wrong hash under a valid signature: a link for one address verifying another.
        $other = URL::temporarySignedRoute('signup.verify', now()->addHour(), [
            'user' => $user->getKey(), 'hash' => sha1('somebody@else.test'),
        ]);
        $this->get($other)->assertForbidden();

        // A broken signature.
        $this->get($this->linkFor($user).'x')->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_the_job_serves_the_landlord_and_is_not_asked_for_a_tenant(): void
    {
        $this->assertFalse(TenantAwareJobs::requiresTenant(new ProvisionCompany(1)));
        $this->assertFalse(TenantAwareJobs::requiresTenant(new SignupVerification('Chai Khana')));
    }

    public function test_the_job_provisions_the_company_on_the_trial_plan_and_the_ready_page_sends_them_in(): void
    {
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        $this->seed(PermissionSeeder::class);
        Notification::fake();

        $this->post('/signup', self::FORM);
        $user = User::where('email', 'ayesha@example.test')->firstOrFail();
        $signup = Signup::where('user_id', $user->getKey())->firstOrFail();

        // Still preparing: the page polls itself.
        $this->actingAs($user)->get('/signup/ready')
            ->assertOk()
            ->assertViewIs('signup.ready')
            ->assertSee('http-equiv="refresh"', false);

        (new ProvisionCompany($signup->getKey()))->handle(app(CompanyProvisioner::class));

        $company = $signup->fresh()->company;
        $this->assertNotNull($company, 'The job did not record the company it made.');
        $this->assertSame('Chai Khana', $company->name);
        $this->assertSame('restaurant', $company->profile);
        $this->assertSame(Plans::trialPlan(), $company->plan);
        $this->assertNotNull($company->trial_ends_at);
        $this->assertEqualsWithDelta(Plans::trialDays(), now()->diffInDays($company->trial_ends_at), 1);

        // Licensed profile ∩ trial plan, exactly as PlanIntersectionTest says.
        modules()->flush();
        foreach (Plans::licencesFor('restaurant', Company::TYPE_BUSINESS, Plans::trialPlan()) as $module) {
            $this->assertTrue(modules()->enabledFor($company->getKey(), $module), "[{$module}] is not usable.");
        }
        $this->assertFalse(modules()->licensedFor($company->getKey(), 'mpr'));

        // The person who signed up is the Administrator.
        $this->assertTrue($company->users()->whereKey($user->getKey())->exists());
        $company->makeCurrent();
        $this->assertTrue($user->fresh()->hasRole('Administrator'));
        Company::forgetCurrent();

        // And the ready page now sends them into their company.
        $this->actingAs($user)->get('/signup/ready')
            ->assertRedirect(Filament::getPanel('admin')->getUrl($company));
    }

    public function test_a_failed_provision_is_shown_rather_than_waited_for(): void
    {
        Notification::fake();

        $this->post('/signup', self::FORM);
        $user = User::where('email', 'ayesha@example.test')->firstOrFail();
        $signup = Signup::where('user_id', $user->getKey())->firstOrFail();

        (new ProvisionCompany($signup->getKey()))->failed(new \RuntimeException('No tenant connection.'));

        $this->assertFalse($signup->fresh()->isPending());
        $this->actingAs($user)->get('/signup/ready')
            ->assertOk()
            ->assertSee('Something went wrong')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_an_existing_email_is_refused_and_nothing_is_attached(): void
    {
        Notification::fake();
        Bus::fake();

        $company = Company::factory()->create();
        $existing = User::factory()->create(['email' => 'ayesha@example.test']);
        $company->users()->attach($existing->getKey());

        $this->from('/signup')->post('/signup', self::FORM)
            ->assertRedirect('/signup')
            ->assertSessionHasErrors('email');

        // The audit's finding 1, at the new door: no signup, no mail, no job, no
        // new membership, and the password the attacker typed is not theirs.
        $this->assertSame(0, Signup::count());
        $this->assertSame(1, User::where('email', 'ayesha@example.test')->count());
        $this->assertSame(1, $existing->companies()->count());
        $this->assertSame(1, Company::count());
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $existing->fresh()->password));
    }

    public function test_an_unverified_signup_submitted_again_is_resent_not_duplicated(): void
    {
        Notification::fake();

        $this->post('/signup', self::FORM);
        $user = User::where('email', 'ayesha@example.test')->firstOrFail();

        $this->post('/signup', self::FORM)->assertOk()->assertViewIs('signup.sent');

        $this->assertSame(1, User::where('email', 'ayesha@example.test')->count());
        $this->assertSame(1, Signup::count());
        Notification::assertSentToTimes($user, SignupVerification::class, 2);
    }

    public function test_a_throttled_submission_answers_exactly_like_a_success(): void
    {
        Notification::fake();

        for ($i = 0; $i < (int) config('plans.signup.per_minute'); $i++) {
            RateLimiter::hit('signup:127.0.0.1');
        }

        $this->post('/signup', self::FORM)
            ->assertOk()
            ->assertViewIs('signup.sent')
            ->assertSee('Chai Khana');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Signup::count());
        Notification::assertNothingSent();
    }

    public function test_a_honeypot_hit_answers_exactly_like_a_success(): void
    {
        Notification::fake();

        $this->post('/signup', self::FORM + ['website' => 'https://spam.example'])
            ->assertOk()
            ->assertViewIs('signup.sent');

        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    public function test_the_panels_do_not_demand_a_verified_email(): void
    {
        // Every user who exists today has a null email_verified_at and was created
        // by a person; the column must not lock any of them out.
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => null]);
        $company->users()->attach($user->getKey());

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
    }

    private function linkFor(User $user): string
    {
        return URL::temporarySignedRoute('signup.verify', now()->addHour(), [
            'user' => $user->getKey(),
            'hash' => sha1($user->email),
        ]);
    }
}
