<?php

namespace Tests\Feature;

use App\Modules\Advances\Filament\Resources\Advances\Pages\CreateAdvance;
use App\Modules\Advances\Models\Advance;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The recovery schedule as it is entered.
 *
 * The months offered under **Skip these months** are generated from the advance's
 * own schedule rather than from a free date, so a skip cannot be entered for a
 * month payroll will never ask about. That is the only arithmetic in the form, and
 * this is what holds it: the list has to start at the month recovery starts, not at
 * the month the money was handed over.
 */
class AdvanceScheduleFormTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'schedule@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['advances', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        // The point under test is what the form offers, not who may reach it.
        Gate::before(fn () => true);
    }

    public function test_the_schedule_is_saved_and_the_skip_months_come_from_it(): void
    {
        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'schedule-borrower@test.local')->id,
            'employee_id' => 'EMP-SCH',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        Livewire::test(CreateAdvance::class)
            ->fillForm([
                'employee_id' => $employee->id,
                'total_amount' => 150000,
                'monthly_instalment' => 50000,
                'started_on' => '2026-07-15',
                'recovery_starts_on' => '2026-10-01',
                'status' => Advance::STATUS_ACTIVE,
            ])
            ->assertFormFieldExists('skipped_months', function ($field): bool {
                $options = $field->getOptions();

                // Three instalments from October, plus the one spare month.
                return array_keys($options) === ['2026-10', '2026-11', '2026-12', '2027-01']
                    && $options['2026-10'] === 'October 2026';
            })
            ->fillForm(['skipped_months' => ['2026-11']])
            ->call('create')
            ->assertHasNoFormErrors();

        $advance = Advance::firstOrFail();

        $this->assertSame('2026-10', $advance->recovery_starts_on->format('Y-m'));
        $this->assertSame(['2026-11'], $advance->skipped_months);
    }
}
