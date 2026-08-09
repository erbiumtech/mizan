<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\ContractorPayments;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\ContractorPaymentSummary;
use App\Support\ModuleMap;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * People paid for work who are not on the payroll.
 *
 * They were already payable — a Beneficiary carries bank details and Payment already
 * pays one — so what was missing was knowing which payees are contractors rather than
 * landlords and utilities, and being able to say at year end what each was paid.
 *
 * No withholding, deliberately. A contractor invoices and settles their own tax;
 * withholding from them would be treating them as staff, which is the mistake this
 * distinction exists to avoid.
 */
class ContractorPaymentsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'contractors@test.local'));
        $this->setCurrentTenant();

        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);
    }

    private function contractor(string $name, array $attributes = []): Beneficiary
    {
        return Beneficiary::create(array_merge([
            'name' => $name,
            'is_active' => true,
            'is_contractor' => true,
            'engagement' => 'Design work',
            'id_type' => 'CNIC',
            'id_number' => '35202-1234567-1',
            'transaction_type_id' => TransactionType::byCode('miscellaneous')?->id,
        ], $attributes));
    }

    private function pay(Beneficiary $payee, float $amount, string $date, string $status = Payment::STATUS_EXPORTED): Payment
    {
        return Payment::create([
            'payable_type' => ModuleMap::alias(Beneficiary::class),
            'payable_id' => $payee->getKey(),
            'transaction_type_id' => $payee->transaction_type_id,
            'amount' => $amount,
            'value_date' => $date,
            'details' => 'Invoice settled',
            'status' => $status,
        ]);
    }

    private function summary(): array
    {
        return app(ContractorPaymentSummary::class)->summary($this->fiscalYear->id);
    }

    public function test_it_totals_what_each_contractor_was_paid(): void
    {
        $designer = $this->contractor('Sana Iqbal');

        $this->pay($designer, 80000, '2026-07-15');
        $this->pay($designer, 45000, '2026-08-15');

        $summary = $this->summary();

        $this->assertCount(1, $summary['contractors']);
        $this->assertSame(125000.0, $summary['contractors'][0]['paid']);
        $this->assertSame(2, $summary['contractors'][0]['payments']);
        $this->assertSame(125000.0, $summary['total']);
    }

    public function test_it_carries_their_tax_identity_and_engagement(): void
    {
        // The two things a year-end statement about somebody needs beyond the amount.
        $this->pay($this->contractor('Sana Iqbal'), 80000, '2026-07-15');

        $row = $this->summary()['contractors'][0];

        $this->assertSame('35202-1234567-1', $row['tax_identity']);
        $this->assertSame('Design work', $row['engagement']);
        $this->assertSame('2026-07-15', $row['last_paid_on']);
    }

    public function test_a_beneficiary_who_is_not_a_contractor_is_left_out(): void
    {
        // The landlord is paid the most of anybody and is not a contractor.
        $landlord = $this->contractor('Ahsan Bhutta', ['is_contractor' => false, 'engagement' => null]);
        $designer = $this->contractor('Sana Iqbal');

        $this->pay($landlord, 92000, '2026-07-01');
        $this->pay($designer, 80000, '2026-07-15');

        $summary = $this->summary();

        $this->assertCount(1, $summary['contractors']);
        $this->assertSame('Sana Iqbal', $summary['contractors'][0]['name']);
        $this->assertSame(80000.0, $summary['total']);
    }

    /** A draft is an intention and an approval is a decision; neither is a receipt. */
    public function test_only_money_that_actually_went_out_counts(): void
    {
        $designer = $this->contractor('Sana Iqbal');

        $this->pay($designer, 10000, '2026-07-10', Payment::STATUS_DRAFT);
        $this->pay($designer, 20000, '2026-07-11', Payment::STATUS_APPROVED);
        $this->pay($designer, 80000, '2026-07-15', Payment::STATUS_EXPORTED);
        $this->pay($designer, 5000, '2026-07-16', Payment::STATUS_PAID);

        $this->assertSame(85000.0, $this->summary()['contractors'][0]['paid']);
    }

    public function test_payments_outside_the_year_are_left_out(): void
    {
        $designer = $this->contractor('Sana Iqbal');

        $this->pay($designer, 80000, '2026-08-15');
        $this->pay($designer, 99000, '2025-08-15');

        $this->assertSame(80000.0, $this->summary()['total']);
    }

    public function test_a_contractor_who_was_never_paid_is_not_listed(): void
    {
        // Somebody engaged and not yet paid does not belong on a statement of what was
        // paid.
        $this->contractor('Sana Iqbal');

        $this->assertCount(0, $this->summary()['contractors']);
    }

    public function test_the_most_paid_comes_first(): void
    {
        $this->pay($this->contractor('Sana Iqbal'), 40000, '2026-07-15');
        $this->pay($this->contractor('Bilal Khan'), 90000, '2026-07-15');

        $this->assertSame('Bilal Khan', $this->summary()['contractors'][0]['name']);
    }

    public function test_the_page_renders(): void
    {
        $this->pay($this->contractor('Sana Iqbal'), 80000, '2026-07-15');

        Livewire::test(ContractorPayments::class)
            ->assertSee('Sana Iqbal')
            ->assertSee('80,000.00')
            ->assertSee('No tax is withheld');
    }

    public function test_a_year_with_no_contractors_says_so(): void
    {
        Livewire::test(ContractorPayments::class)->assertSee('No contractor was paid');
    }
}
