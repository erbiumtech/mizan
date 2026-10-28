<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Invoicing\Services\RecurringInvoiceService;
use App\Notifications\InvoiceIssued;
use App\Notifications\RecordChanged;
use App\Support\Subscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Automatic issue of the subscription invoices — docs/saas-launch-plan.md §6.
 *
 * Only `auto_issue` drafts; through the same `issue()` a person's click calls;
 * three guards that hold rather than send.
 */
class BillingIssueTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Company $customer;

    private User $operatorAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'admin@customer.test'));
        $this->customer = $this->setCurrentTenant();
        $this->customer->forceFill(['plan' => 'growth'])->save();

        Company::factory()->create(['slug' => 'erbium-ops', 'name' => 'Erbium Ops']);
        config(['saas.operator_company' => 'erbium-ops']);

        $this->operatorAdmin = User::factory()->create(['is_super_admin' => true, 'status' => 1]);

        $this->employees(3);
    }

    private function employees(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Employee::create([
                'employee_id' => 'EMP-'.str_pad((string) (Employee::count() + 1), 3, '0', STR_PAD_LEFT),
                'name' => "Employee {$i}",
                'is_active' => true,
                'date_of_joining' => '2026-01-01',
            ]);
        }
    }

    private function subscribe(?Company $company = null, string $country = 'PK', ?string $currency = null): Company
    {
        $company ??= $this->customer;
        app(Subscriptions::class)->subscribe($company, "billing@{$company->slug}.test", 'An address', $country, $currency);

        return $company->fresh();
    }

    private function raise(string $period): void
    {
        Carbon::setTestNow(Carbon::parse($period)->startOfMonth()->setTime(3, 0));
        app(RecurringInvoiceService::class)->generateFor(Carbon::parse($period));
    }

    private function issue(string $period): array
    {
        Carbon::setTestNow(Carbon::parse($period)->startOfMonth()->setTime(4, 0));

        return app(Subscriptions::class)->issue(Carbon::parse($period));
    }

    public function test_only_auto_issue_drafts_are_issued_and_they_are_emailed(): void
    {
        Notification::fake();
        $this->subscribe();

        // Somebody else's agreement in the operator's books — a person's to issue.
        $manual = RecurringInvoice::create([
            'contact_id' => $this->customer->fresh()->billing_contact_id, 'description' => 'Consulting',
            'day_of_month' => 1, 'due_days' => 15, 'starts_on' => '2026-11-01', 'is_active' => false,
        ]);
        $manual->update(['is_active' => true, 'contact_id' => \App\Modules\Invoicing\Models\Contact::create([
            'name' => 'Walk-in Ltd', 'kind' => 'customer', 'is_active' => true, 'email' => 'walkin@example.test',
        ])->getKey()]);
        $manual->lines()->create([
            'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 5000,
            'account_id' => \App\Modules\Accounting\Models\Account::where('code', '4100')->firstOrFail()->id,
        ]);

        $this->raise('2026-11-01');
        $this->assertSame(2, Invoice::where('status', Invoice::STATUS_DRAFT)->count());

        $result = $this->issue('2026-11-01');

        $this->assertCount(1, $result['issued']);
        $this->assertSame([], $result['held']);
        $this->assertSame([], $result['failed']);

        $subscription = Invoice::where('recurring_invoice_id', '!=', $manual->getKey())->firstOrFail();
        $this->assertSame(Invoice::STATUS_ISSUED, $subscription->status);
        $this->assertNotNull($subscription->journal_entry_id, 'Issuing posted nothing.');
        Notification::assertSentOnDemand(InvoiceIssued::class);

        $this->assertSame(Invoice::STATUS_DRAFT, Invoice::where('recurring_invoice_id', $manual->getKey())->value('status'));
    }

    public function test_a_seat_count_that_jumped_is_held_and_the_operator_is_told(): void
    {
        Notification::fake();
        $this->subscribe();

        $this->raise('2026-11-01');
        $this->issue('2026-11-01');

        // Thirty where there were ten: a hiring spree, or a folding bug.
        $this->employees(27);
        app(Subscriptions::class)->meter($this->customer->fresh());
        $this->raise('2026-12-01');

        $result = $this->issue('2026-12-01');

        $this->assertCount(1, $result['held']);
        $this->assertSame([], $result['issued']);
        $this->assertSame(Invoice::STATUS_DRAFT, Invoice::whereDate('period', '2026-12-01')->value('status'));
        Notification::assertSentTo($this->operatorAdmin, RecordChanged::class, fn (RecordChanged $n): bool => str_contains($n->body, 'from 10 to 30'));
    }

    public function test_a_modest_change_in_seats_issues(): void
    {
        Notification::fake();
        $this->subscribe();

        $this->raise('2026-11-01');
        $this->issue('2026-11-01');

        $this->employees(9); // 12: a fifth over ten
        app(Subscriptions::class)->meter($this->customer->fresh());
        $this->raise('2026-12-01');

        $result = $this->issue('2026-12-01');

        $this->assertCount(1, $result['issued']);
        $this->assertSame(Invoice::STATUS_ISSUED, Invoice::whereDate('period', '2026-12-01')->value('status'));
    }

    public function test_a_company_suspended_by_hand_is_held_but_one_suspended_for_overdue_is_invoiced(): void
    {
        Notification::fake();
        $this->subscribe();
        $this->raise('2026-11-01');

        $this->customer->forceFill(['status' => 0, 'suspended_reason' => null])->save();
        $result = $this->issue('2026-11-01');
        $this->assertCount(1, $result['held']);
        $this->assertSame(Invoice::STATUS_DRAFT, Invoice::whereDate('period', '2026-11-01')->value('status'));
        Notification::assertSentTo($this->operatorAdmin, RecordChanged::class, fn (RecordChanged $n): bool => str_contains($n->body, 'suspended by hand'));

        $this->customer->forceFill(['status' => 0, 'suspended_reason' => Subscriptions::REASON_OVERDUE])->save();
        $result = $this->issue('2026-11-01');
        $this->assertCount(1, $result['issued']);
        $this->assertSame(Invoice::STATUS_ISSUED, Invoice::whereDate('period', '2026-11-01')->value('status'));
    }

    public function test_one_draft_that_throws_does_not_stop_the_rest_and_the_command_exits_non_zero(): void
    {
        Notification::fake();
        $this->subscribe();

        // A foreign customer with no exchange rate recorded: issuing it throws.
        $foreign = Company::factory()->create(['slug' => 'dubai-co', 'plan' => 'business']);
        $this->subscribe($foreign, 'AE', 'USD');

        $this->raise('2026-11-01');
        $this->assertSame(2, Invoice::where('status', Invoice::STATUS_DRAFT)->count());

        $result = $this->issue('2026-11-01');

        $this->assertCount(1, $result['issued']);
        $this->assertCount(1, $result['failed']);
        $this->assertStringContainsString('No exchange rate for USD', $result['failed'][0]);
        $this->assertSame(1, Invoice::where('status', Invoice::STATUS_ISSUED)->count());
        $this->assertSame(1, Invoice::where('status', Invoice::STATUS_DRAFT)->count());
        Notification::assertSentTo($this->operatorAdmin, RecordChanged::class, fn (RecordChanged $n): bool => $n->title === 'Subscription invoice not issued');

        // Run again, by the command: the USD draft is still there, still failing, and the exit says so.
        $this->artisan('billing:issue', ['--period' => '2026-11-01'])
            ->expectsOutputToContain('1 failed')
            ->assertFailed();
    }

    public function test_nothing_but_subscribe_sets_auto_issue(): void
    {
        $agreement = RecurringInvoice::create([
            'contact_id' => \App\Modules\Invoicing\Models\Contact::create(['name' => 'X', 'kind' => 'customer', 'is_active' => true])->getKey(),
            'description' => 'Plain', 'day_of_month' => 1, 'due_days' => 15, 'starts_on' => '2026-11-01',
        ]);

        $this->assertFalse($agreement->fresh()->auto_issue);
        $this->assertSame(1, $agreement->fresh()->every_months);
        $this->assertNull($agreement->fresh()->currency_code);
    }
}
