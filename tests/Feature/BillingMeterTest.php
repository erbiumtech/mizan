<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Support\Plans;
use App\Support\Subscriptions;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Subscribing and metering — docs/saas-launch-plan.md §3.1 and §6.
 *
 * The operator is a second company in the same test database, which is exactly
 * the single-database shape Subscriptions::within() exists for: every table is
 * every company's table here, so a customer's employees and the operator's
 * agreements sit side by side and the assertions read both.
 */
class BillingMeterTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Company $customer;

    private Company $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'admin@customer.test'));
        $this->customer = $this->setCurrentTenant();
        $this->customer->forceFill(['plan' => 'growth', 'trial_ends_at' => now()->addDays(10)])->save();

        $this->operator = Company::factory()->create(['slug' => 'erbium-ops', 'name' => 'Erbium Ops']);
        config(['saas.operator_company' => 'erbium-ops']);

        $this->employees(3, active: true);
        $this->employees(1, active: false);
    }

    private function employees(int $count, bool $active): void
    {
        for ($i = 0; $i < $count; $i++) {
            Employee::create([
                'employee_id' => 'EMP-'.str_pad((string) (Employee::count() + 1), 3, '0', STR_PAD_LEFT),
                'name' => "Employee {$i}",
                'is_active' => $active,
                'date_of_joining' => '2026-01-01',
            ]);
        }
    }

    private function subscriptions(): Subscriptions
    {
        return app(Subscriptions::class);
    }

    public function test_a_pakistani_address_subscribes_monthly_in_rupees(): void
    {
        $this->subscriptions()->subscribe($this->customer, 'billing@customer.test', '12 Main Boulevard, Lahore', 'PK', null);

        $company = $this->customer->fresh();
        $this->assertSame('PKR', $company->billing_currency);
        $this->assertNotNull($company->billing_contact_id);
        $this->assertNull($company->trial_ends_at, 'Subscribing did not end the trial.');
        $this->assertSame(3, $company->seats);
        $this->assertNotNull($company->seats_counted_at);

        $contact = Contact::findOrFail($company->billing_contact_id);
        $this->assertSame($company->name, $contact->name);
        $this->assertSame('billing@customer.test', $contact->correspondenceEmail());
        $this->assertSame(Contact::KIND_CUSTOMER, $contact->kind);

        $agreement = RecurringInvoice::where('contact_id', $contact->getKey())->firstOrFail();
        $this->assertTrue($agreement->auto_issue, 'Subscribe is the one place auto_issue is set.');
        $this->assertSame(1, $agreement->every_months);
        $this->assertNull($agreement->currency_code, 'PKR is the base: null on the agreement, as on an invoice.');
        $this->assertSame('2026-11-01', $agreement->starts_on->toDateString(), 'The first invoice is next month\'s.');
        $this->assertTrue($agreement->coversPeriod(Carbon::parse('2026-11-01')));
        $this->assertTrue($agreement->coversPeriod(Carbon::parse('2026-12-01')));

        $line = $agreement->lines->first();
        $this->assertSame((float) Plans::price('growth', 'PKR'), (float) $line->unit_price);
        $this->assertSame((float) Plans::minimumSeats(), (float) $line->quantity, 'Three employees pay as the minimum.');
        $this->assertStringContainsString('3 active employees (minimum '.Plans::minimumSeats().')', $line->description);
        $this->assertStringContainsString('× 1 month', $line->description);
    }

    public function test_a_foreign_address_picks_a_currency_and_is_billed_quarterly_in_advance(): void
    {
        $this->subscriptions()->subscribe($this->customer, 'ap@customer.test', '1 Rue de la Paix, Paris', 'FR', 'EUR');

        $company = $this->customer->fresh();
        $this->assertSame('EUR', $company->billing_currency);

        $agreement = RecurringInvoice::where('contact_id', $company->billing_contact_id)->firstOrFail();
        $this->assertSame(3, $agreement->every_months);
        $this->assertSame('EUR', $agreement->currency_code);

        // Runs in November, February, May — not December.
        $this->assertTrue($agreement->coversPeriod(Carbon::parse('2026-11-15')));
        $this->assertFalse($agreement->coversPeriod(Carbon::parse('2026-12-15')));
        $this->assertFalse($agreement->coversPeriod(Carbon::parse('2027-01-15')));
        $this->assertTrue($agreement->coversPeriod(Carbon::parse('2027-02-15')));

        $line = $agreement->lines->first();
        $this->assertSame((float) Plans::price('growth', 'EUR'), (float) $line->unit_price);
        $this->assertSame((float) (Plans::minimumSeats() * 3), (float) $line->quantity, 'The minimum, times three months.');
        $this->assertStringContainsString('× 3 months', $line->description);
    }

    public function test_the_currency_rule_is_the_billing_address(): void
    {
        $rule = $this->subscriptions();

        $this->assertSame('PKR', $rule->currencyFor('PK', null));
        $this->assertSame('PKR', $rule->currencyFor('pk', 'PKR'));
        $this->assertSame('USD', $rule->currencyFor('AE', 'USD'));
        $this->assertSame('EUR', $rule->currencyFor('DE', 'eur'));

        try {
            $rule->currencyFor('PK', 'USD');
            $this->fail('A Pakistani address picked dollars.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('billed in PKR', $e->getMessage());
        }

        foreach ([null, 'PKR', 'GBP'] as $bad) {
            try {
                $rule->currencyFor('AE', $bad);
                $this->fail('A foreign address was billed in '.var_export($bad, true).'.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Pick a currency', $e->getMessage());
            }
        }
    }

    public function test_a_company_on_no_plan_cannot_subscribe_and_nobody_subscribes_twice(): void
    {
        $this->customer->forceFill(['plan' => null])->save();

        try {
            $this->subscriptions()->subscribe($this->customer->fresh(), 'x@y.test', 'Somewhere', 'PK', null);
            $this->fail('Subscribed with no plan.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no plan', $e->getMessage());
        }

        $this->customer->forceFill(['plan' => 'starter'])->save();
        $this->subscriptions()->subscribe($this->customer->fresh(), 'x@y.test', 'Somewhere', 'PK', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already subscribed');
        $this->subscriptions()->subscribe($this->customer->fresh(), 'x@y.test', 'Somewhere', 'PK', null);

        $this->assertSame(1, Contact::count());
    }

    public function test_the_meter_writes_the_count_and_the_description_and_never_the_price(): void
    {
        $this->subscriptions()->subscribe($this->customer, 'billing@customer.test', 'Lahore', 'PK', null);
        $agreement = RecurringInvoice::where('contact_id', $this->customer->fresh()->billing_contact_id)->firstOrFail();

        // Headcount grows past the minimum; the list price changes in the file.
        $this->employees(9, active: true);
        config(['plans.plans.growth.prices.PKR' => 999]);
        Carbon::setTestNow('2026-12-01 02:00:00');

        $seats = $this->subscriptions()->meter($this->customer->fresh());

        $this->assertSame(12, $seats);
        $this->assertSame(12, $this->customer->fresh()->seats);
        $this->assertSame('2026-12-01', $this->customer->fresh()->seats_counted_at->toDateString());

        $line = $agreement->fresh()->lines->first();
        $this->assertSame(12.0, (float) $line->quantity);
        $this->assertStringContainsString('12 active employees × 1 month, counted 2026-12-01', $line->description);
        $this->assertStringNotContainsString('minimum', $line->description);
        $this->assertSame((float) 800, (float) $line->unit_price, 'The meter rewrote the price the customer was promised.');
    }

    public function test_the_meter_refuses_a_customer_with_two_agreements(): void
    {
        $this->subscriptions()->subscribe($this->customer, 'billing@customer.test', 'Lahore', 'PK', null);
        $contactId = $this->customer->fresh()->billing_contact_id;

        RecurringInvoice::create([
            'contact_id' => $contactId, 'description' => 'A second one', 'day_of_month' => 1,
            'due_days' => 15, 'starts_on' => '2026-11-01',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2 active agreements');

        $this->subscriptions()->meter($this->customer->fresh());
    }

    public function test_the_command_keeps_going_and_exits_non_zero_when_one_customer_cannot_be_metered(): void
    {
        $this->subscriptions()->subscribe($this->customer, 'billing@customer.test', 'Lahore', 'PK', null);

        $other = Company::factory()->create(['slug' => 'other-co', 'plan' => 'starter']);
        $this->subscriptions()->subscribe($other, 'ap@other.test', 'Karachi', 'PK', null);
        RecurringInvoice::create([
            'contact_id' => $other->fresh()->billing_contact_id, 'description' => 'A second one',
            'day_of_month' => 1, 'due_days' => 15, 'starts_on' => '2026-11-01',
        ]);

        $this->employees(2, active: true);

        $this->artisan('billing:meter')
            ->expectsOutputToContain('other-co')
            ->assertFailed();

        $this->assertSame(5, $this->customer->fresh()->seats, 'The customer that could be metered was not.');
    }

    public function test_the_commands_do_nothing_when_no_operator_is_configured(): void
    {
        config(['saas.operator_company' => null]);

        $this->artisan('billing:meter')->expectsOutputToContain('bills nobody')->assertSuccessful();
        $this->artisan('billing:issue')->expectsOutputToContain('bills nobody')->assertSuccessful();
        $this->artisan('billing:enforce')->expectsOutputToContain('bills nobody')->assertSuccessful();
    }
}
