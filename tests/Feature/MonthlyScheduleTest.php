<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\SubscriptionBillingService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use Illuminate\Support\Carbon;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The 26th: the month's payslips, and the month's standing payments.
 *
 * Both run unattended on a cron, so the case that matters is the second run —
 * a retry, a manual catch-up, two workers. Nothing here may raise the rent twice
 * or disturb a payslip somebody has already corrected.
 */
class MonthlyScheduleTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Beneficiary $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'schedule@test.local'));
        $this->setCurrentTenant();

        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);

        $this->landlord = Beneficiary::create([
            'name' => 'Mr Ahmed Khan',
            'is_active' => true,
            'transaction_type_id' => TransactionType::byCode('rent')?->id,
        ]);
    }

    private function subscription(array $attributes = []): BeneficiarySubscription
    {
        return BeneficiarySubscription::create(array_merge([
            'beneficiary_id' => $this->landlord->id,
            'description' => 'House rent',
            'amount' => 92000,
            'due_day' => 1,
            'starts_on' => '2026-07-01',
            'is_active' => true,
        ], $attributes));
    }

    private function employee(string $email, bool $withSettings = true): Employee
    {
        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => 'EMP-'.substr(md5($email), 0, 5),
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'is_active' => 1,
        ]);

        if ($withSettings) {
            EmployeeSetting::create([
                'employee_id' => $employee->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'basic_wage' => 400000,
            ]);
        }

        return $employee;
    }

    // ---- Subscriptions -----------------------------------------------------

    public function test_a_running_subscription_is_raised_as_a_draft(): void
    {
        $subscription = $this->subscription();

        $raised = app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15'));

        $this->assertCount(1, $raised);

        $payment = $raised->first();
        $this->assertSame(Payment::STATUS_DRAFT, $payment->status);
        $this->assertSame(92000.0, (float) $payment->amount);
        $this->assertSame($subscription->id, $payment->beneficiary_subscription_id);
        $this->assertSame('2026-08-01', $payment->period->toDateString());
        $this->assertStringContainsString('August 2026', $payment->details);
    }

    /** The whole point of a cron job that may run more than once. */
    public function test_running_it_twice_does_not_raise_the_rent_twice(): void
    {
        $this->subscription();
        $billing = app(SubscriptionBillingService::class);

        $billing->generateFor(Carbon::parse('2026-08-15'));
        $second = $billing->generateFor(Carbon::parse('2026-08-15'));

        $this->assertCount(0, $second);
        $this->assertSame(1, Payment::count());
    }

    public function test_the_database_refuses_a_duplicate_even_so(): void
    {
        // Two workers, two transactions, neither seeing the other's row. The check
        // in the service is the courtesy; this is the guarantee.
        $subscription = $this->subscription();
        app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15'));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Payment::create([
            'payable_type' => \App\Support\ModuleMap::alias(Beneficiary::class),
            'payable_id' => $this->landlord->id,
            'transaction_type_id' => TransactionType::byCode('rent')->id,
            'beneficiary_subscription_id' => $subscription->id,
            'period' => '2026-08-01',
            'amount' => 92000,
            'details' => 'House rent — August 2026',
            'status' => Payment::STATUS_DRAFT,
        ]);
    }

    public function test_each_month_is_raised_separately(): void
    {
        $this->subscription();
        $billing = app(SubscriptionBillingService::class);

        $billing->generateFor(Carbon::parse('2026-08-15'));
        $billing->generateFor(Carbon::parse('2026-09-15'));

        $this->assertSame(2, Payment::count());
        $this->assertSame(
            ['2026-08-01', '2026-09-01'],
            Payment::orderBy('period')->pluck('period')->map(fn ($d) => $d->toDateString())->all(),
        );
    }

    public function test_a_subscription_that_has_not_started_is_left_alone(): void
    {
        $this->subscription(['starts_on' => '2026-10-01']);

        $this->assertCount(0, app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15')));
    }

    public function test_a_finished_subscription_is_left_alone(): void
    {
        $this->subscription(['ends_on' => '2026-07-31']);

        $this->assertCount(0, app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15')));
    }

    public function test_its_last_month_is_billed_in_full(): void
    {
        // A monthly agreement ending mid-month is not pro-rated by this system, and
        // dropping the month entirely would be the wrong answer to that.
        $this->subscription(['ends_on' => '2026-08-14']);

        $this->assertCount(1, app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15')));
    }

    public function test_switching_one_off_stops_it(): void
    {
        $this->subscription(['is_active' => false]);

        $this->assertCount(0, app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15')));
    }

    public function test_a_due_day_past_by_the_run_is_not_back_dated(): void
    {
        // The rent was due on the 1st and the run is the 26th. Dating the payment
        // the 1st would post it into the ledger behind the day it was raised.
        $this->subscription(['due_day' => 1]);

        $payment = app(SubscriptionBillingService::class)
            ->generateFor(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-26'))
            ->first();

        $this->assertSame('2026-08-26', $payment->value_date->toDateString());
    }

    public function test_a_due_day_still_ahead_keeps_its_date(): void
    {
        $this->subscription(['due_day' => 28]);

        $payment = app(SubscriptionBillingService::class)
            ->generateFor(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-26'))
            ->first();

        $this->assertSame('2026-08-28', $payment->value_date->toDateString());
    }

    public function test_a_due_day_after_the_end_of_a_short_month(): void
    {
        // The 31st of February is the 28th, not the 3rd of March.
        $this->subscription(['due_day' => 31]);

        $payment = app(SubscriptionBillingService::class)
            ->generateFor(Carbon::parse('2027-02-01'), Carbon::parse('2027-02-01'))
            ->first();

        $this->assertSame('2027-02-28', $payment->value_date->toDateString());
    }

    public function test_it_falls_back_to_the_beneficiarys_transaction_type(): void
    {
        $this->subscription(['transaction_type_id' => null]);

        $payment = app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15'))->first();

        $this->assertSame(TransactionType::byCode('rent')->id, $payment->transaction_type_id);
    }

    public function test_a_subscription_with_no_type_anywhere_is_refused(): void
    {
        // Rather than raised as a payment that can never be approved.
        $this->landlord->update(['transaction_type_id' => null]);
        $this->subscription(['transaction_type_id' => null]);

        $this->expectExceptionMessage('has no transaction type');

        app(SubscriptionBillingService::class)->generateFor(Carbon::parse('2026-08-15'));
    }

    // ---- Payslips ----------------------------------------------------------

    public function test_the_month_opens_with_a_payslip_each(): void
    {
        $this->employee('one@test.local');
        $this->employee('two@test.local');

        $created = app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        $this->assertCount(2, $created);
        $this->assertSame(2, Payslip::where('month', 'August')->count());
    }

    public function test_opening_the_month_twice_adds_nothing(): void
    {
        $this->employee('one@test.local');
        $payroll = app(MonthlyPayrollService::class);

        $payroll->openMonth('August', $this->fiscalYear);
        $second = $payroll->openMonth('August', $this->fiscalYear);

        $this->assertCount(0, $second);
        $this->assertSame(1, Payslip::where('month', 'August')->count());
    }

    /**
     * The month is opened on the 26th and worked on for days afterwards. A rerun
     * must not recalculate a payslip somebody has corrected or an employee has
     * accepted.
     */
    public function test_a_rerun_leaves_a_corrected_payslip_alone(): void
    {
        $employee = $this->employee('one@test.local');
        $payroll = app(MonthlyPayrollService::class);

        $payroll->openMonth('August', $this->fiscalYear);

        $payslip = Payslip::where('employee_id', $employee->id)->firstOrFail();
        $payslip->update(['paid_days' => 22, 'bonus' => 15000]);
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $payroll->openMonth('August', $this->fiscalYear);

        $payslip->refresh();
        $this->assertSame(22, (int) $payslip->paid_days);
        $this->assertSame(15000.0, (float) $payslip->bonus);
        $this->assertSame(Payslip::REVIEW_ACCEPTED, $payslip->employee_review);
    }

    public function test_an_employee_with_no_package_is_skipped_and_reported(): void
    {
        // Raising an empty payslip would put a zero in the payroll and a name in
        // the bank file; passing over them silently loses a person.
        $this->employee('paid@test.local');
        $unpaid = $this->employee('nopackage@test.local', withSettings: false);

        $payroll = app(MonthlyPayrollService::class);

        $this->assertCount(1, $payroll->openMonth('August', $this->fiscalYear));
        $this->assertSame(
            [$unpaid->id],
            $payroll->employeesWithoutASetting('August', $this->fiscalYear)->pluck('id')->all(),
        );
    }

    public function test_an_inactive_employee_gets_no_payslip(): void
    {
        $this->employee('one@test.local');
        $this->employee('left@test.local')->update(['is_active' => 0]);

        $this->assertCount(1, app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear));
    }

    // ---- The schedule itself -----------------------------------------------

    /**
     * Asserted against the services above rather than by running the commands:
     * both are wrapped in Spatie's TenantAware, which iterates real per-tenant
     * database connections and cannot run in this single-database suite — the
     * same reason PayrollAccountCheckTest tests its audit rather than its command.
     *
     * What is left to prove here is that they exist and that they fire on the day
     * they are supposed to, which is the whole of the request.
     */
    public function test_both_commands_are_registered(): void
    {
        $commands = \Illuminate\Support\Facades\Artisan::all();

        $this->assertArrayHasKey('payroll:open-month', $commands);
        $this->assertArrayHasKey('accounting:raise-subscriptions', $commands);
    }

    public function test_they_run_on_the_twenty_sixth_of_each_month(): void
    {
        $expressions = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->mapWithKeys(fn ($event): array => [$event->command => $event->expression]);

        $of = fn (string $command): ?string => $expressions
            ->first(fn ($expression, $key): bool => str_contains($key, $command));

        // minute hour day-of-month month day-of-week
        $this->assertSame('0 2 26 * *', $of('payroll:open-month'), 'payslips, 26th at 02:00');
        $this->assertSame('10 2 26 * *', $of('accounting:raise-subscriptions'), 'subscriptions, 26th at 02:10');
    }
}
