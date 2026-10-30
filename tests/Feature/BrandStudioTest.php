<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\BusinessCardOrder;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeIdCard;
use App\Modules\Employees\Services\BusinessCards;
use App\Modules\Employees\Services\EmailSignature;
use App\Modules\Employees\Services\IdCards;
use App\Support\BrandKit;
use App\Support\DocumentVerification;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Brand Studio — docs/saas-launch-plan.md §14.10.
 *
 * The judgements worth pinning: that one live card each is enforced rather than
 * hoped for, that a cancelled card still verifies as cancelled, and that a colour
 * somebody typed never reaches a style attribute.
 */
class BrandStudioTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->makeUser('Administrator', 'brand-hr@test.local');
        $this->actingAs($this->hr);
        $this->setCurrentTenant();

        $staff = $this->makeUser('Employee', 'brand-staff@test.local');
        $staff->forceFill(['name' => 'Ayesha Khan'])->save();

        $this->employee = Employee::create([
            'user_id' => $staff->getKey(), 'employee_id' => 'EMP-B1', 'name' => 'Ayesha Khan',
            'gender' => 'Female', 'is_active' => true, 'designation' => 'Backend Developer',
            'phone' => '0300 1234567', 'date_of_joining' => '2021-04-01',
        ]);

        app(TenantSettings::class)->set('company.legal_name', 'Karachi Widgets (Private) Limited');
    }

    // --------------------------------------------------------------- brand kit

    public function test_a_colour_that_is_not_a_colour_never_reaches_the_card(): void
    {
        $settings = app(TenantSettings::class);

        $this->assertSame(BrandKit::DEFAULT_COLOUR, BrandKit::colour(), 'nothing chosen is the shipped one');

        $settings->set('company.brand.colour', '#AA3366');
        $this->assertSame('#aa3366', BrandKit::colour());

        // The one failure that matters: these values are printed into a style attribute
        // in a PDF and into HTML somebody pastes into a mail client.
        $settings->set('company.brand.colour', 'red; background:url(x)');
        $this->assertSame(BrandKit::DEFAULT_COLOUR, BrandKit::colour());

        $settings->set('company.brand.typeface', 'comic-sans');
        $this->assertSame(BrandKit::DEFAULT_TYPEFACE, BrandKit::typeface(), 'a PDF has to have the font');
    }

    // ---------------------------------------------------------------- id cards

    public function test_issuing_a_card_cancels_whatever_that_person_was_holding(): void
    {
        $cards = app(IdCards::class);

        $first = $cards->issue($this->employee);
        $second = $cards->issue($this->employee);

        $this->assertNotSame($first->serial, $second->serial, 'a replacement is its own card, not the same one');
        $this->assertTrue($first->refresh()->isRevoked());
        $this->assertFalse($second->refresh()->isRevoked());

        $this->assertSame(
            1,
            EmployeeIdCard::query()->where('employee_id', $this->employee->getKey())->live()->count(),
            'one live card each, which is what makes a lost card cancellable',
        );
    }

    public function test_the_serial_carries_the_year_and_runs_on(): void
    {
        $cards = app(IdCards::class);

        $this->assertSame(now()->year.'-0001', $cards->issue($this->employee)->serial);
        $this->assertSame(now()->year.'-0002', $cards->issue($this->employee)->serial);
    }

    public function test_a_cancelled_card_verifies_as_cancelled_rather_than_as_missing(): void
    {
        app(TenantSettings::class)->set('documents.verification.enabled', true);
        app(TenantSettings::class)->set('documents.verification.token', str_repeat('a', 32));

        $card = app(IdCards::class)->issue($this->employee);

        $this->assertSame('Valid', $card->state());
        $this->assertSame(DocumentVerification::MATCHES, DocumentVerification::verify(
            EmployeeIdCard::KIND,
            (int) $card->getKey(),
            DocumentVerification::hashFor(EmployeeIdCard::KIND, (int) $card->getKey(), $card->verificationFacts()),
        )['verdict']);

        app(IdCards::class)->revoke($card, 'Lost on site');

        $card->refresh();
        $this->assertSame('Cancelled', $card->state());
        $this->assertSame('Cancelled', $card->verificationFacts()['Status']);
    }

    public function test_an_expired_card_is_expired_rather_than_cancelled(): void
    {
        $card = app(IdCards::class)->issue($this->employee, '2020-01-01');

        $this->assertSame('Expired', $card->state());
        $this->assertFalse($card->isRevoked(), 'nobody cancelled it; it simply ran out');
    }

    public function test_a_card_renders_at_the_size_of_a_bank_card(): void
    {
        $card = app(IdCards::class)->issue($this->employee);
        $html = app(IdCards::class)->render($card)->html();

        $this->assertStringContainsString('Ayesha Khan', $html);
        $this->assertStringContainsString('EMP-B1', $html);
        $this->assertStringContainsString($card->serial, $html);
        $this->assertStringContainsString('85.6mm 54mm', $html);
    }

    public function test_a_cancelled_card_prints_as_cancelled(): void
    {
        $card = app(IdCards::class)->issue($this->employee);
        app(IdCards::class)->revoke($card, 'Lost');

        $this->assertStringContainsString('CANCELLED', app(IdCards::class)->render($card->refresh())->html());
    }

    // ----------------------------------------------------------- business cards

    public function test_a_card_says_what_the_record_says_unless_somebody_overrode_it(): void
    {
        $order = BusinessCardOrder::create(['employee_id' => $this->employee->getKey(), 'quantity' => 100]);

        $this->assertSame('Backend Developer', $order->title());
        $this->assertSame('0300 1234567', $order->phone());

        $order->update(['title_override' => 'Engineering Lead', 'phone_override' => '042 111 2222']);

        $this->assertSame('Engineering Lead', $order->refresh()->title());
        $this->assertSame('042 111 2222', $order->phone());
    }

    public function test_the_artwork_carries_the_company_and_the_person(): void
    {
        $order = BusinessCardOrder::create(['employee_id' => $this->employee->getKey()]);

        $html = app(BusinessCards::class)->render($order)->html();

        $this->assertStringContainsString('Ayesha Khan', $html);
        $this->assertStringContainsString('Karachi Widgets (Private) Limited', $html);
        $this->assertStringContainsString('90mm 55mm', $html);
    }

    // -------------------------------------------------------- email signature

    public function test_the_signature_is_built_from_the_record(): void
    {
        $html = app(EmailSignature::class)->html($this->employee);

        $this->assertStringContainsString('Ayesha Khan', $html);
        $this->assertStringContainsString('Backend Developer', $html);
        $this->assertStringContainsString('Karachi Widgets (Private) Limited', $html);
        $this->assertStringContainsString('brand-staff@test.local', $html);

        // A table with inline styles, because mail clients strip stylesheets.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('<style', $html);

        // No image: a logo in a signature is an attachment on every mail, blocked by
        // default in half the clients that receive it.
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_the_signature_leaves_out_what_the_record_does_not_have(): void
    {
        $this->employee->update(['phone' => null]);

        $html = app(EmailSignature::class)->html($this->employee->refresh());

        $this->assertStringNotContainsString('T&nbsp;', $html);
        $this->assertStringContainsString('brand-staff@test.local', $html, 'and keeps what it does have');
    }

    public function test_the_plain_text_version_says_the_same_things(): void
    {
        $text = app(EmailSignature::class)->text($this->employee);

        $this->assertStringContainsString('Ayesha Khan', $text);
        $this->assertStringContainsString('Karachi Widgets (Private) Limited', $text);
        $this->assertStringNotContainsString('<', $text);
    }
}
