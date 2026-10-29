<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Employees\Services\AppointmentLetter;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Notifications\OfferIssued;
use App\Modules\Recruitment\Services\OfferLetter;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Offer and appointment letters — docs/saas-launch-plan.md §7.2.
 *
 * The siblings' rule, pinned for the two new ones: a letter refuses with a blank
 * letterhead or a missing fact, and prints only what the record backs.
 */
class OfferLetterTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 10:00:00');
        Notification::fake();

        $this->actingAs($this->makeUser('Administrator', 'hr-offer@test.local'));
        $this->setCurrentTenant();
    }

    private function letterhead(): void
    {
        $settings = app(TenantSettings::class);

        foreach ([
            'company.legal_name' => 'ERBIUMTECH (SMC-Private) Limited',
            'company.address' => 'Office 5, Gulberg III, Lahore',
            'company.ntn' => '1234567-8',
            'company.signatory_name' => 'Muzafar Ali',
        ] as $key => $value) {
            $settings->set($key, $value);
        }
    }

    private function offer(array $attributes = []): Offer
    {
        $vacancy = Vacancy::create([
            'code' => 'V-1', 'title' => 'Accountant', 'department' => 'Finance',
            'designation' => 'Senior Accountant', 'employment_type' => 'Permanent', 'status' => Vacancy::STATUS_OPEN,
        ]);
        $applicant = Applicant::create(['name' => 'Sana Iqbal', 'email' => 'sana@example.test']);
        $application = Application::create([
            'vacancy_id' => $vacancy->id, 'applicant_id' => $applicant->id,
            'applied_on' => '2026-08-01', 'stage' => Application::STAGE_OFFER,
        ]);

        return Offer::create(array_merge([
            'application_id' => $application->id, 'salary' => 150000, 'joining_date' => '2026-12-01',
            'status' => Offer::STATUS_ISSUED, 'issued_at' => now(),
            'components' => ['medical_allowance' => 15000, 'petrol_allowance' => 0],
        ], $attributes));
    }

    public function test_an_offer_letter_refuses_with_a_blank_letterhead_or_a_missing_fact(): void
    {
        $offer = $this->offer();
        $missing = app(OfferLetter::class)->missingFor($offer);

        $this->assertNotSame([], $missing);
        $this->assertStringContainsString('Letterhead', implode(' ', $missing));

        $this->letterhead();
        $this->assertSame([], app(OfferLetter::class)->missingFor($offer->fresh()));

        $offer->update(['salary' => 0]);
        $this->assertStringContainsString('basic salary', implode(' ', app(OfferLetter::class)->missingFor($offer->fresh())));
    }

    public function test_an_offer_letter_prints_the_terms_from_the_offer_and_nothing_else(): void
    {
        $this->letterhead();
        $offer = $this->offer();

        $letters = app(OfferLetter::class);
        $data = $letters->data($offer, ['valid_days' => 10]);

        $this->assertSame('Senior Accountant', $data['position']);
        $this->assertSame(165000.0, $data['gross'], 'Gross is basic plus the components that are not zero.');
        $this->assertSame([['label' => 'Medical Allowance', 'amount' => 15000.0]], $data['components']);
        $this->assertSame('2026-11-07', $data['valid_until']->toDateString());

        $html = $letters->renderPdf($offer, ['valid_days' => 10])->html();

        foreach (['Sana Iqbal', 'Senior Accountant', 'Finance', '150,000', '15,000', '165,000', '01 December 2026', '07 November 2026', 'ERBIUMTECH'] as $fact) {
            $this->assertStringContainsString($fact, $html, "The letter does not say {$fact}.");
        }

        $this->assertStringNotContainsString('Petrol', $html, 'A zero component was printed.');
        $this->assertStringStartsWith('offer-letter-sana-iqbal-2026-10-28', $letters->filename($offer));
    }

    public function test_the_offer_can_be_emailed_to_the_candidate_with_the_letter_attached(): void
    {
        $this->letterhead();
        $offer = $this->offer();

        Notification::route('mail', 'sana@example.test')->notify(new OfferIssued($offer, ['valid_days' => 7]));

        Notification::assertSentOnDemand(OfferIssued::class, function (OfferIssued $notification, array $channels, object $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return in_array('sana@example.test', (array) ($notifiable->routes['mail'] ?? []), true)
                && str_contains($mail->subject, 'Senior Accountant')
                && count($mail->rawAttachments) === 1
                && str_starts_with($mail->rawAttachments[0]['name'], 'offer-letter-sana-iqbal');
        });
    }

    public function test_an_appointment_letter_needs_a_package_in_force_on_the_joining_date(): void
    {
        $this->letterhead();

        $employee = Employee::create([
            'employee_id' => 'EMP-014', 'name' => 'Muhammad Hammad', 'gender' => 'Male', 'is_active' => true,
            'designation' => 'Senior Full Stack Developer', 'department' => 'IT', 'employment_type' => 'permanent',
            'date_of_joining' => '2026-09-01',
        ]);

        $letters = app(AppointmentLetter::class);
        $missing = $letters->missingFor($employee);
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('salary package in force on the joining date', $missing[0]);

        EmployeeSetting::create([
            'employee_id' => $employee->getKey(), 'fiscal_year_id' => $this->fiscalYear->getKey(),
            'start_date' => '2026-09-01', 'end_date' => '2027-06-30',
            'basic_wage' => 200000, 'medical_allowance' => 20000,
        ]);

        $this->assertSame([], $letters->missingFor($employee->fresh()));

        $html = $letters->renderPdf($employee->fresh(), ['probation_months' => 6])->html();

        foreach (['Muhammad Hammad', 'Senior Full Stack Developer', 'IT', '01 September 2026', '220,000', 'first 6 months', 'EMP-014/APT'] as $fact) {
            $this->assertStringContainsString($fact, $html, "The letter does not say {$fact}.");
        }

        $this->assertStringNotContainsString('probation', $letters->renderPdf($employee->fresh(), ['probation_months' => 0])->html());
    }
}
