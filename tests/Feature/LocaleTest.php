<?php

namespace Tests\Feature;

use App\Modules\Accounting\Support\AmountWords;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Support\Locales;
use App\Support\Pdf\Pdf;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Locale resolution, the document language per kind, and the engine guard —
 * docs/saas-launch-plan.md §8.10.
 */
class LocaleTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'en', 'multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);
        $this->user = User::factory()->create(['status' => 1]);
        $this->actingAs($this->user);
        $this->setCurrentTenant();
    }

    public function test_the_interface_language_is_the_persons_then_the_companys_then_englishs(): void
    {
        $this->assertSame('en', Locales::forUser($this->user));

        app(TenantSettings::class)->set('company.locale', 'ur');
        $this->assertSame('ur', Locales::forUser($this->user), 'the company default');

        // forceFill: `preferences` is guarded, written by the profile page and this test alone.
        $this->user->forceFill(['preferences' => ['locale' => 'en']])->save();
        $this->assertSame('en', Locales::forUser($this->user->fresh()), "the person's own wins");

        $this->user->forceFill(['preferences' => ['locale' => 'fr']])->save();
        $this->assertSame('ur', Locales::forUser($this->user->fresh()), 'a language not on offer is not a language');

        // Laravel sends every notification in the recipient's language without a caller asking.
        $this->assertSame('ur', $this->user->fresh()->preferredLocale());
    }

    public function test_the_api_reads_accept_language(): void
    {
        $this->assertSame('ur', Locales::fromAcceptLanguage('ur-PK,ur;q=0.9,en;q=0.8'));
        $this->assertSame('en', Locales::fromAcceptLanguage('en-GB,en;q=0.9'));
        $this->assertNull(Locales::fromAcceptLanguage('fr-FR,fr;q=0.9'));
        $this->assertNull(Locales::fromAcceptLanguage(null));

        CompanyModule::updateOrCreate(['company_id' => $this->tenant->getKey(), 'module' => 'employees'], ['licensed' => true, 'enabled' => true]);
        modules()->flush();
        Sanctum::actingAs($this->user, ['api']);

        // The route answers 404 for a user with no employee record; the point here is that the
        // middleware ran before the controller did, which it does whatever the controller answers.
        $this->withHeader('Accept-Language', 'ur-PK')->getJson('/api/v1/my-profile');
        $this->assertSame('ur', app()->getLocale(), 'the header set the request language');
    }

    public function test_a_documents_language_is_the_kinds_then_the_companys_then_english(): void
    {
        $this->assertSame('en', Locales::forDocument('payslip'));

        app(TenantSettings::class)->set('documents.locale', 'ur');
        $this->assertSame('ur', Locales::forDocument('payslip'));
        $this->assertSame('ur', Locales::forDocument(null));

        // A factory wants Urdu payslips and English bank letters: two settings, not a support call.
        app(TenantSettings::class)->set('documents.locale.letter', 'en');
        $this->assertSame('en', Locales::forDocument('letter'));
        $this->assertSame('ur', Locales::forDocument('payslip'));
    }

    public function test_a_right_to_left_document_refuses_the_fallback_engine_and_says_why(): void
    {
        app(TenantSettings::class)->set('documents.locale.payslip', 'ur');
        config(['pdf.driver' => 'dompdf']);

        $pdf = Pdf::document('payslip', 'pdfs.delivery-test', []);
        $this->assertSame('ur', $pdf->locale());
        $this->assertTrue($pdf->isRtl());

        try {
            $pdf->raw();
            $this->fail('Dompdf rendered an Urdu document.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Chromium renderer', $e->getMessage());
            $this->assertStringContainsString('disjointed letters', $e->getMessage());
        }

        // English on the same engine is untouched.
        $this->assertFalse(Pdf::document('letter', 'pdfs.delivery-test', [])->isRtl());
    }

    public function test_a_cheque_in_urdu_spells_its_amount_in_urdu_with_western_digits(): void
    {
        $this->assertSame('Rupees Twelve Lakh Fifty Thousand Only', AmountWords::write(1250000, 'Rupees', 'en'));

        $urdu = AmountWords::write(1250000, 'Rupees', 'ur');
        $this->assertSame('صرف بارہ لاکھ پچاس ہزار روپے', $urdu);
        $this->assertStringContainsString('صرف', $urdu, '"only" opens the line, as the leaf expects');

        $this->assertSame('صرف ایک سو پانچ روپے اور پچاس پیسے', AmountWords::write(105.5, 'Rupees', 'ur'));
        $this->assertSame('صرف صفر روپے', AmountWords::write(0, 'Rupees', 'ur'));
        $this->assertMatchesRegularExpression('/^[^0-9]*$/u', $urdu, 'words, not digits');
    }
}
