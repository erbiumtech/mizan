<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Models\Payslip;
use App\Notifications\PayslipIssued;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Releasing the month from the payslips list.
 *
 * The list is where payroll works, so this is where sending belongs — and where
 * "who has not had theirs yet" has to be answerable, which is what the Sent
 * column and its filter are for.
 */
class PayslipSendActionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'payroll@test.local'));
        $this->setCurrentTenant();

        Notification::fake();
    }

    private function payslip(string $name, string $code): Payslip
    {
        $user = $this->makeUser('Employee', str($name)->slug().'@test.local');
        $user->update(['name' => $name]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => $code,
            'gender' => 'Male',
            'phone' => '0300-1234567',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 300000,
        ]);

        return Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    public function test_a_payslip_is_sent_from_its_row(): void
    {
        $payslip = $this->payslip('Ayesha Khan', 'EMP-1');

        Livewire::test(ListPayslips::class)
            ->callAction(TestAction::make('sendPayslip')->table($payslip->getKey()));

        $this->assertTrue($payslip->fresh()->wasSent());
        Notification::assertSentTo($payslip->employee->user, PayslipIssued::class);
    }

    public function test_the_month_is_sent_in_one_go(): void
    {
        $first = $this->payslip('Ayesha Khan', 'EMP-1');
        $second = $this->payslip('Bilal Ahmed', 'EMP-2');

        Livewire::test(ListPayslips::class)
            ->selectTableRecords([$first->getKey(), $second->getKey()])
            ->callAction(TestAction::make('sendPayslipBulk')->table()->bulk());

        $this->assertTrue($first->fresh()->wasSent());
        $this->assertTrue($second->fresh()->wasSent());
        Notification::assertSentTimes(PayslipIssued::class, 2);
    }

    /**
     * The reason the bulk action skips rather than refuses: a month is released,
     * then two payslips are corrected and released again, and the people whose
     * figures never changed must not get a second copy.
     */
    public function test_sending_the_month_again_skips_whoever_already_has_theirs(): void
    {
        $already = $this->payslip('Ayesha Khan', 'EMP-1');
        $fresh = $this->payslip('Bilal Ahmed', 'EMP-2');

        Livewire::test(ListPayslips::class)
            ->selectTableRecords([$already->getKey()])
            ->callAction(TestAction::make('sendPayslipBulk')->table()->bulk());

        Notification::assertSentTimes(PayslipIssued::class, 1);

        Livewire::test(ListPayslips::class)
            ->selectTableRecords([$already->getKey(), $fresh->getKey()])
            ->callAction(TestAction::make('sendPayslipBulk')->table()->bulk());

        // The second run sent one more, not two.
        Notification::assertSentTimes(PayslipIssued::class, 2);
        $this->assertTrue($fresh->fresh()->wasSent());
    }

    /** Row-level resend is deliberate, and reads as a resend. */
    public function test_a_row_can_be_sent_again(): void
    {
        $payslip = $this->payslip('Ayesha Khan', 'EMP-1');

        Livewire::test(ListPayslips::class)
            ->callAction(TestAction::make('sendPayslip')->table($payslip->getKey()));

        Livewire::test(ListPayslips::class)
            ->assertActionHasLabel(TestAction::make('sendPayslip')->table($payslip->getKey()), 'Resend')
            ->callAction(TestAction::make('sendPayslip')->table($payslip->getKey()));

        Notification::assertSentTimes(PayslipIssued::class, 2);
    }

    public function test_the_list_says_who_has_had_theirs(): void
    {
        $sent = $this->payslip('Ayesha Khan', 'EMP-1');
        $this->payslip('Bilal Ahmed', 'EMP-2');

        Livewire::test(ListPayslips::class)
            ->callAction(TestAction::make('sendPayslip')->table($sent->getKey()))
            ->assertSee('not sent')
            ->assertSee('sent');
    }

    /**
     * Asserted on the employee's own payslip, the only one they can see: releasing
     * a payslip is payroll's, and somebody sending themselves their own would make
     * the Sent column a claim nobody checked.
     */
    public function test_an_employee_cannot_send_their_own_payslip(): void
    {
        $payslip = $this->payslip('Ayesha Khan', 'EMP-1');

        $this->actingAs($payslip->employee->user);

        Livewire::test(ListPayslips::class)
            ->assertCanSeeTableRecords([$payslip])
            ->assertActionHidden(TestAction::make('sendPayslip')->table($payslip->getKey()));

        Notification::assertNothingSent();
    }
}
