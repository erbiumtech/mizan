<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The careers page — docs/saas-launch-plan.md §7.4. Two gates, 404 for both;
 * a fixed field list; a throttled or duplicate submission answers exactly like
 * a success; and it never advances a stage.
 */
class CareersPageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-careers-token';

    private Company $company;

    private Vacancy $vacancy;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
        ]]);

        $this->company = Company::factory()->create(['slug' => 'acme']);
        $this->company->makeCurrent();

        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => 'recruitment'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $this->vacancy = Vacancy::create([
            'code' => 'V-1', 'title' => 'Accountant', 'department' => 'Finance', 'designation' => 'Senior Accountant',
            'employment_type' => 'Permanent', 'status' => Vacancy::STATUS_OPEN, 'openings' => 2, 'description' => 'Keep the books.',
        ]);
        Vacancy::create(['code' => 'V-2', 'title' => 'Driver', 'status' => Vacancy::STATUS_CLOSED]);

        RateLimiter::clear('careers:'.$this->company->getKey());
        RateLimiter::clear('careers:'.$this->company->getKey().':127.0.0.1');
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        parent::tearDown();
    }

    private function openTheGates(): void
    {
        app(TenantSettings::class)->set('recruitment.careers.enabled', true);
        app(TenantSettings::class)->set('recruitment.careers.token', self::TOKEN);
    }

    private function url(string $suffix = '', ?string $token = null): string
    {
        return '/careers/acme/'.($token ?? self::TOKEN).$suffix;
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function apply(array $overrides = [], ?string $token = null)
    {
        Company::forgetCurrent();

        $response = $this->post($this->url('/'.$this->vacancy->getKey(), $token), $overrides + [
            'name' => 'Sana Iqbal', 'email' => 'Sana@Example.test', 'phone' => '0300-1112222', 'cover' => 'I like ledgers.',
        ]);

        $this->company->makeCurrent();

        return $response;
    }

    public function test_both_gates_must_be_open(): void
    {
        Company::forgetCurrent();
        $this->get($this->url())->assertNotFound();

        app(TenantSettings::class)->set('recruitment.careers.enabled', true);
        Company::forgetCurrent();
        $this->get($this->url())->assertNotFound();

        $this->openTheGates();
        Company::forgetCurrent();
        $this->get($this->url('', 'wrong-token'))->assertNotFound();
        $this->get($this->url())->assertOk()->assertSee('Accountant')->assertDontSee('Driver');
        $this->get($this->url('/'.$this->vacancy->getKey()))->assertOk()->assertSee('Keep the books.')->assertSee('Send application');
        $this->get($this->url('/9999'))->assertNotFound();
    }

    public function test_an_application_lands_at_applied_with_the_cv_and_nothing_moves_it(): void
    {
        $this->openTheGates();

        $this->apply(['resume' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf')])
            ->assertOk()
            ->assertViewIs('careers.sent')
            ->assertSee('Thank you');

        $applicant = Applicant::where('email', 'sana@example.test')->firstOrFail();
        $this->assertSame('Sana Iqbal', $applicant->name);
        $this->assertSame('careers', $applicant->source);
        $this->assertStringStartsWith('applicant-resumes/', $applicant->resume_path);
        $this->assertStringContainsString('I like ledgers.', (string) $applicant->notes);

        $application = Application::where('applicant_id', $applicant->getKey())->firstOrFail();
        $this->assertSame(Application::STAGE_APPLIED, $application->stage);
        $this->assertSame($this->vacancy->getKey(), $application->vacancy_id);

        // Pressing twice is one candidate, one application.
        $this->apply()->assertOk()->assertViewIs('careers.sent');
        $this->assertSame(1, Applicant::count());
        $this->assertSame(1, Application::count());
    }

    public function test_a_throttled_or_honeypot_submission_answers_exactly_like_a_success(): void
    {
        $this->openTheGates();

        $this->apply(['website' => 'http://spam.example'])->assertOk()->assertViewIs('careers.sent');
        $this->assertSame(0, Applicant::count());

        for ($i = 0; $i < (int) config('recruitment.careers.per_minute_per_ip'); $i++) {
            RateLimiter::hit('careers:'.$this->company->getKey().':127.0.0.1');
        }

        $this->apply()->assertOk()->assertViewIs('careers.sent');
        $this->assertSame(0, Applicant::count());
    }

    public function test_the_settings_section_writes_what_the_gates_read(): void
    {
        $section = new \App\Modules\Recruitment\Filament\Settings\CareersSettingsSection;

        $section->save(['recruitment_careers_enabled' => true, 'recruitment_careers_token' => self::TOKEN]);

        $this->assertTrue((bool) setting('recruitment.careers.enabled'));
        $this->assertSame(self::TOKEN, setting('recruitment.careers.token'));
        $this->assertSame(['recruitment_careers_enabled' => true, 'recruitment_careers_token' => self::TOKEN], $section->fill());
    }
}
