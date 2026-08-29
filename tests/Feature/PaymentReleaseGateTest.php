<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Events\PayslipReviewed;
use App\Modules\Payroll\Listeners\CopyReviewOntoPayment;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\SalaryPaymentGenerator;
use Database\Seeders\TransactionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A salary is held back until the payslip is accepted — decided from the payment's own row.
 *
 * `Payment::isReleasable()` used to load `payslip->employee_review`, which was the last thing making
 * Accounting depend on Payroll for a *rule* rather than a figure. The decision is now copied onto
 * `payments.subject_review` at two moments: when the payment is generated (the payslip is usually already
 * reviewed by then) and when a review is recorded afterwards, through `PayslipReviewed` and
 * `CopyReviewOntoPayment`. See docs/module-packaging-plan.md §8 Group C.
 *
 * **A copy can go stale, and that is the whole risk of this change.** `PaymentBatchTest` covers the
 * behaviour a user sees; this file covers the machinery that keeps the copy true, because a stale copy
 * does not throw — it releases a salary whose payslip was rejected, or holds one that was accepted, and
 * looks entirely normal doing it.
 *
 * The vocabularies are asserted too. Accounting declares its own `Payment::REVIEW_*` so it need not read
 * `Payslip::REVIEW_*`, which means two constants now have to agree by convention rather than by
 * reference — exactly the sort of thing that drifts once and is never noticed.
 */
class PaymentReleaseGateTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'gate@test.local'));
        $this->setCurrentTenant();

        $this->seed(TransactionTypeSeeder::class);
    }

    private function employee(string $email): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-'.substr(md5($email), 0, 5),
            'name' => 'Test Person',
            'gender' => 'Male',
            'is_active' => true,
        ]);
    }

    /** A payslip in a given review state, set on the column — the fixtures need a state, not the workflow. */
    private function payslip(Employee $employee, ?string $review, string $month = 'July'): Payslip
    {
        $payslip = Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
            'basic_wage' => 100000,
            'net_salary' => 90000,
        ]);

        if ($review !== null) {
            DB::table('payslips')->where('id', $payslip->id)->update(['employee_review' => $review]);
        }

        return $payslip->fresh();
    }

    private function generate(string $month = 'July'): void
    {
        app(SalaryPaymentGenerator::class)->generate($month, $this->fiscalYear);
    }

    /**
     * The two vocabularies agree.
     *
     * If they ever diverge, every gate silently inverts: a payment whose `subject_review` reads
     * `accepted` from Payroll would not match Accounting's own constant, and no salary would release.
     */
    public function test_the_two_review_vocabularies_have_not_drifted(): void
    {
        $this->assertSame(Payslip::REVIEW_ACCEPTED, Payment::REVIEW_ACCEPTED);
        $this->assertSame(Payslip::REVIEW_REJECTED, Payment::REVIEW_REJECTED);
        // The third value, added when payroll gained a way to answer an objection: the payslip writes it and
        // this row is a copy, so a mismatch here would hold a released salary back for ever.
        $this->assertSame(Payslip::REVIEW_OVERRIDDEN, Payment::REVIEW_OVERRIDDEN);
    }

    /** Generating a payment for an already-reviewed payslip carries the decision across. */
    public function test_generation_copies_the_review_onto_the_payment(): void
    {
        $accepted = $this->payslip($this->employee('a@test.local'), Payslip::REVIEW_ACCEPTED);

        $this->generate();

        $payment = Payment::where('payslip_id', $accepted->getKey())->firstOrFail();

        $this->assertSame(Payment::REVIEW_ACCEPTED, $payment->subject_review);
        $this->assertTrue($payment->isReleasable());
    }

    /**
     * Reviewing afterwards updates the payment that is already waiting.
     *
     * This is the order the listener exists for, and the one nothing else covers: the bank file was opened
     * first, the employee accepted second.
     */
    public function test_reviewing_after_generation_unblocks_the_payment(): void
    {
        $payslip = $this->payslip($this->employee('b@test.local'), null);

        $this->generate();

        $payment = Payment::where('payslip_id', $payslip->getKey())->firstOrFail();
        $this->assertFalse($payment->isReleasable(), 'an unreviewed payslip should hold its salary');

        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $this->assertTrue($payment->refresh()->isReleasable(), 'accepting the payslip did not reach the payment');
    }

    /** And a rejection carries its reason, which is what the blocked row shows. */
    public function test_a_rejection_reaches_the_payment_with_its_reason(): void
    {
        $payslip = $this->payslip($this->employee('c@test.local'), null);

        $this->generate();

        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime is missing');

        $payment = Payment::where('payslip_id', $payslip->getKey())->firstOrFail()->refresh();

        $this->assertFalse($payment->isReleasable());
        $this->assertSame(Payment::BLOCK_REJECTED, $payment->releaseBlockedCategory());
        $this->assertStringContainsString('Overtime is missing', (string) $payment->releaseBlockedReason());
    }

    /**
     * And payroll answering the objection releases it again.
     *
     * The case the rejection gate had no exit from: `recordEmployeeReview()` refuses a second review, so
     * before `resolveObjection()` existed an employee who objected to a payslip that turned out to be right
     * left their own salary unreleasable from every screen in the application. Asserted through the payment
     * rather than the payslip, because the payment is what the bank file reads and it holds its own copy.
     */
    public function test_answering_the_objection_releases_the_payment(): void
    {
        $payslip = $this->payslip($this->employee('override@test.local'), null);

        $this->generate();

        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime is missing');

        $payment = Payment::where('payslip_id', $payslip->getKey())->firstOrFail()->refresh();
        $this->assertFalse($payment->isReleasable());

        // A reply first: `resolveObjection()` refuses to close an objection nobody has responded to.
        $payslip->comments()->create(['user_id' => auth()->id(), 'body' => 'Checked the timesheet — the hours are right.']);

        $payslip->refresh()->resolveObjection();

        $payment->refresh();

        $this->assertSame(Payment::REVIEW_OVERRIDDEN, $payment->subject_review);
        $this->assertTrue($payment->isReleasable(), 'answering the objection did not reach the payment');
        $this->assertNull($payment->releaseBlockedReason());
        $this->assertNull($payment->releaseBlockedCategory());

        // The objection itself is still on the payment, which is what a later question about this salary
        // going out over a complaint would be answered from.
        $this->assertSame('Overtime is missing', $payment->subject_review_reason);
    }

    /**
     * The event fires, and it fires with the payslip.
     *
     * Asserted separately from the listener's effect because the two fail differently: a listener that is
     * never registered and an event that is never dispatched look identical from the payment's side.
     */
    public function test_recording_a_review_dispatches_the_event(): void
    {
        Event::fake([PayslipReviewed::class]);

        $payslip = $this->payslip($this->employee('d@test.local'), null);
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        Event::assertDispatched(
            PayslipReviewed::class,
            fn (PayslipReviewed $event): bool => $event->payslip->is($payslip),
        );
    }

    /**
     * And the listener is wired to it.
     *
     * Asked of the real dispatcher rather than through `Event::assertListening`, which exists only on the
     * fake — and a fake is the wrong instrument here, since what is in doubt is whether the *application*
     * registered the listener at boot.
     */
    public function test_the_listener_is_registered_for_the_event(): void
    {
        $registered = array_map(
            fn ($listener): string => is_string($listener) ? $listener : (new \ReflectionFunction($listener))->getName(),
            Event::getListeners(PayslipReviewed::class),
        );

        $this->assertNotEmpty($registered, 'nothing listens for PayslipReviewed, so no payment is ever updated');

        $this->assertTrue(
            collect(Event::getRawListeners()[PayslipReviewed::class] ?? [])
                ->contains(fn ($l): bool => $l === CopyReviewOntoPayment::class),
            'CopyReviewOntoPayment is not registered for PayslipReviewed',
        );
    }

    /**
     * A payment with no payslip behind it is not waiting for anything.
     *
     * Rent, a supplier, a reimbursement — `subject_review` is null and the gate has to read that as "go",
     * not as "unaccepted". Inverting this would hold back every non-salary payment in the company.
     */
    public function test_a_payment_with_no_payslip_is_releasable(): void
    {
        $payment = Payment::create([
            'payable_type' => \App\Support\ModuleMap::alias(Employee::class),
            'payable_id' => $this->employee('e@test.local')->getKey(),
            'transaction_type_id' => TransactionType::byCode('salary')?->id,
            'amount' => 5000,
            'details' => 'Office rent',
            'status' => Payment::STATUS_DRAFT,
        ]);

        $this->assertNull($payment->subject_review);
        $this->assertTrue($payment->isReleasable());
    }

    /**
     * The relation Payroll contributes still works, including in a query.
     *
     * `Payment` no longer declares `payslip()`; Payroll registers it. Three call sites depend on that —
     * two eager loads and a `whereHas` that scopes the bank file to a month — and a `belongsTo` registered
     * this way infers its foreign key from the *closure's* name unless told, which produced a query for
     * `payments.app\_modules\_payroll\{closure}_id` the first time.
     */
    public function test_the_contributed_payslip_relation_resolves_and_queries(): void
    {
        $payslip = $this->payslip($this->employee('f@test.local'), Payslip::REVIEW_ACCEPTED);

        $this->generate();

        $payment = Payment::with('payslip')->where('payslip_id', $payslip->getKey())->firstOrFail();

        $this->assertTrue($payment->payslip->is($payslip));

        $this->assertTrue(
            Payment::whereHas('payslip', fn ($q) => $q->where('month', 'July'))->exists(),
            'whereHas on the contributed relation found nothing — check the foreign key is named',
        );
    }
}
