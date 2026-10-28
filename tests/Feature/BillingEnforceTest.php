<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Services\RecurringInvoiceService;
use App\Notifications\SubscriptionNotice;
use App\Support\Subscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * billing:enforce — docs/saas-launch-plan.md §6. Three rules, and the one that
 * matters most: a suspension a person imposed is never lifted by the job.
 */
class BillingEnforceTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 09:00:00');
        Notification::fake();

        $this->actingAs($this->makeUser('Administrator', 'admin@customer.test'));
        $this->customer = $this->setCurrentTenant();
        $this->customer->forceFill(['plan' => 'growth', 'trial_ends_at' => now()->addDays(10)])->save();

        Company::factory()->create(['slug' => 'erbium-ops', 'name' => 'Erbium Ops']);
        config(['saas.operator_company' => 'erbium-ops']);

        Employee::create(['employee_id' => 'EMP-001', 'name' => 'One', 'is_active' => true, 'date_of_joining' => '2026-01-01']);
    }

    private function enforce(): array
    {
        return app(Subscriptions::class)->enforce();
    }

    public function test_a_trial_past_its_grace_is_suspended_and_the_signup_is_told(): void
    {
        $this->customer->forceFill(['trial_ends_at' => now()->subDays(3)])->save();
        $this->assertSame([], $this->enforce()['suspended'], 'Suspended inside the grace period.');
        $this->assertSame(1, (int) $this->customer->fresh()->status);

        $this->customer->forceFill(['trial_ends_at' => now()->subDays(8)])->save();
        $result = $this->enforce();

        $this->assertSame([$this->customer->slug], $result['suspended']);
        $company = $this->customer->fresh();
        $this->assertSame(0, (int) $company->status);
        $this->assertSame(Subscriptions::REASON_TRIAL_EXPIRED, $company->suspended_reason);

        Notification::assertSentOnDemand(SubscriptionNotice::class, function (SubscriptionNotice $notice, array $channels, object $notifiable): bool {
            return $notice->reason === Subscriptions::REASON_TRIAL_EXPIRED
                && in_array('admin@customer.test', (array) ($notifiable->routes['mail'] ?? []), true);
        });

        // Suspended means out: the audit's finding 12, now with a reason on the row.
        $this->assertFalse(auth()->user()->canAccessTenant($company));
    }

    public function test_subscribing_lifts_a_trial_suspension(): void
    {
        $this->customer->forceFill(['status' => 0, 'suspended_reason' => Subscriptions::REASON_TRIAL_EXPIRED, 'trial_ends_at' => now()->subDays(20)])->save();

        $this->assertSame([], $this->enforce()['lifted']);

        app(Subscriptions::class)->subscribe($this->customer->fresh(), 'billing@customer.test', 'Lahore', 'PK', null);
        $result = $this->enforce();

        $this->assertSame([$this->customer->slug], $result['lifted']);
        $this->assertSame(1, (int) $this->customer->fresh()->status);
        $this->assertNull($this->customer->fresh()->suspended_reason);
    }

    public function test_an_invoice_long_overdue_suspends_and_paying_it_lifts(): void
    {
        app(Subscriptions::class)->subscribe($this->customer, 'billing@customer.test', 'Lahore', 'PK', null);

        Carbon::setTestNow('2026-11-01 03:00:00');
        app(RecurringInvoiceService::class)->generateFor(now());
        $invoice = Invoice::where('contact_id', $this->customer->fresh()->billing_contact_id)->firstOrFail();
        app(InvoiceService::class)->issue($invoice);

        // Due 16 November; 29 days late is dunning's business, not ours.
        Carbon::setTestNow('2026-12-15 05:00:00');
        $this->assertSame([], $this->enforce()['suspended']);

        Carbon::setTestNow('2026-12-18 05:00:00');
        $result = $this->enforce();
        $this->assertSame([$this->customer->slug], $result['suspended']);
        $this->assertSame(Subscriptions::REASON_OVERDUE, $this->customer->fresh()->suspended_reason);
        Notification::assertSentOnDemand(SubscriptionNotice::class, fn (SubscriptionNotice $n): bool => $n->reason === Subscriptions::REASON_OVERDUE);

        // Paid in full: access returns on its own.
        $invoice->fresh()->update(['status' => Invoice::STATUS_PAID, 'amount_paid' => $invoice->fresh()->total]);
        $result = $this->enforce();
        $this->assertSame([$this->customer->slug], $result['lifted']);
        $this->assertSame(1, (int) $this->customer->fresh()->status);
    }

    public function test_a_suspension_a_person_imposed_is_never_lifted(): void
    {
        $this->customer->forceFill(['status' => 0, 'suspended_reason' => null])->save();

        $result = $this->enforce();

        $this->assertSame([], $result['lifted']);
        $this->assertSame(0, (int) $this->customer->fresh()->status);

        // Even once they subscribe: a person decided, a person undoes it.
        app(Subscriptions::class)->subscribe($this->customer->fresh(), 'billing@customer.test', 'Lahore', 'PK', null);
        $this->assertSame([], $this->enforce()['lifted']);
        $this->assertSame(0, (int) $this->customer->fresh()->status);
    }

    public function test_a_company_on_no_plan_is_never_touched(): void
    {
        $byHand = Company::factory()->create(['slug' => 'by-hand', 'plan' => null, 'trial_ends_at' => now()->subYear()]);

        $result = $this->enforce();

        $this->assertNotContains('by-hand', $result['suspended']);
        $this->assertSame(1, (int) $byHand->fresh()->status);
    }
}
