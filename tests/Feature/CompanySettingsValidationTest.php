<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\CompanySettings;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Both KeyValue maps on Company Settings are validated on save.
 *
 * A wrong payroll account code breaks posting at the point of use; a malformed
 * iPayments header field is only rejected when the file reaches the bank. Either
 * way the mistake surfaces far from the save, and neither field is addable, so a
 * bad save used to be hard to undo from the page.
 */
class CompanySettingsValidationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'settings@test.local'));
        $this->setCurrentTenant();
    }

    private function page(): Testable
    {
        return Livewire::test(CompanySettings::class);
    }

    /**
     * Edits values in place in a KeyValue's row list — its state is a list of
     * ['key' => ..., 'value' => ...] rows, not an associative map, so replacing
     * by key would silently add a row instead of changing one.
     *
     * @param  array<string, mixed>  $overrides  field => new value
     */
    private function saveKeyValue(string $field, array $overrides): Testable
    {
        $page = $this->page();
        $rows = $page->get("data.{$field}");

        foreach ($rows as $i => $row) {
            $key = $row['key'] ?? null;

            if ($key !== null && array_key_exists($key, $overrides)) {
                $rows[$i]['value'] = $overrides[$key];
                unset($overrides[$key]);
            }
        }

        $this->assertSame([], $overrides, 'every override must match an existing row');

        return $page->set("data.{$field}", $rows)->call('save');
    }

    /** @param array<string, mixed> $ipayments */
    private function saveIpayments(array $ipayments): Testable
    {
        return $this->saveKeyValue('ipayments', $ipayments);
    }

    public function test_the_shipped_defaults_pass_validation(): void
    {
        $this->page()->call('save')->assertHasNoErrors();
    }

    public function test_a_malformed_swift_code_is_rejected(): void
    {
        $this->saveIpayments(['debit_bank_id' => 'NOPE'])
            ->assertHasErrors('data.ipayments');
    }

    public function test_a_valid_eleven_character_swift_code_passes(): void
    {
        $this->saveIpayments(['debit_bank_id' => 'SCBLPKKXXXX'])
            ->assertHasNoErrors();
    }

    public function test_a_valid_eight_character_swift_code_passes(): void
    {
        $this->saveIpayments(['debit_bank_id' => 'SCBLPKKX'])
            ->assertHasNoErrors();
    }

    public function test_a_bad_country_code_is_rejected(): void
    {
        $this->saveIpayments(['debit_country' => 'PAK'])
            ->assertHasErrors('data.ipayments');
    }

    public function test_a_bad_currency_code_is_rejected(): void
    {
        $this->saveIpayments(['currency' => 'RUPEES'])
            ->assertHasErrors('data.ipayments');
    }

    public function test_a_non_numeric_purpose_code_is_rejected(): void
    {
        $this->saveIpayments(['purpose_of_payment' => 'salary'])
            ->assertHasErrors('data.ipayments');
    }

    /** Blank means "use the shipped default", so it must not be rejected. */
    public function test_a_blank_field_is_allowed(): void
    {
        $this->saveIpayments(['debit_account' => ''])
            ->assertHasNoErrors();
    }

    public function test_a_valid_change_is_stored(): void
    {
        $this->saveIpayments(['debit_city' => 'LHE'])->assertHasNoErrors();

        app(TenantSettings::class)->flush();

        $this->assertSame('LHE', setting('ipayments')['debit_city']);
    }

    /**
     * The nested own_bank matching rules decide whether a beneficiary is paid by
     * account number or IBAN. They must survive a settings save untouched — a
     * KeyValue cannot edit them, and losing them would silently send IBANs to
     * SCB accounts.
     */
    public function test_the_own_bank_matching_rules_are_not_editable_and_survive_a_save(): void
    {
        $filled = $this->page()->get('data')['ipayments'];

        $keys = collect($filled)
            ->map(fn ($row) => is_array($row) ? ($row['key'] ?? null) : null)
            ->filter()
            ->all();

        $this->assertNotContains('own_bank', $keys, 'nested config must not be offered for editing');

        $this->page()->call('save')->assertHasNoErrors();
        app(TenantSettings::class)->flush();

        $this->assertSame(
            config('ipayments.own_bank'),
            setting('ipayments')['own_bank'],
            'the matching rules must still be there after a save'
        );
    }

    // --- payroll account codes ----------------------------------------------

    public function test_a_payroll_code_that_is_not_in_the_chart_is_rejected(): void
    {
        $this->saveKeyValue('accounting_payroll_accounts', ['basic_wage' => '9999'])
            ->assertHasErrors('data.accounting_payroll_accounts');
    }

    public function test_a_payroll_code_that_exists_is_accepted(): void
    {
        $this->saveKeyValue('accounting_payroll_accounts', ['basic_wage' => '5200'])
            ->assertHasNoErrors();
    }
}
