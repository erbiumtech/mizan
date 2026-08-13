<?php

namespace Database\Seeders;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * The leave types a company starts with.
 *
 * **These numbers are defaults, not law, and the help text says so.** Statutory
 * minima in Pakistan are *provincial* — Sindh, Punjab, KP and Balochistan each
 * legislate their own shops-and-establishments rules — so what is shipped here is a
 * starting point a company's HR must confirm. This application already takes that
 * position on tax slabs ("a Finance Act change is a re-seed"), and it should not
 * pretend to more certainty about labour law than it has. docs/hrms-plan.md §4.1.
 *
 * Runs at provisioning, for the profiles that license `leave`. A company provisioned
 * without a profile gets no types, which is the honest outcome: the no-profile path is
 * TenantBaselineSeeder's and licenses no leave, so seeding types there would create
 * reference data for a module nobody bought. Such a company runs this seeder when it
 * buys Leave.
 *
 * firstOrCreate on `code`, so re-running adds what is missing and never overwrites a
 * company's edited day counts — the numbers here are exactly the thing HR is expected
 * to change.
 */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'annual',
                'label' => 'Annual Leave',
                'days_per_year' => 14,
                // The one type that carries by default, and only when the company
                // switches leave.carry_forward on — a cap without the switch carries
                // nothing. Casual and sick are deliberately 0: unused casual leave
                // carrying into next year is nobody's policy.
                'max_carry_forward' => 5,
                'is_encashable' => true,
                'min_notice_days' => 7,
                'sort' => 10,
            ],
            [
                'code' => 'casual',
                'label' => 'Casual Leave',
                'days_per_year' => 10,
                // Short notice is the *point* of casual leave, so 0 rather than a
                // number that leave.min_notice_enforced would then block on.
                'min_notice_days' => 0,
                'sort' => 20,
            ],
            [
                'code' => 'sick',
                'label' => 'Sick Leave',
                'days_per_year' => 8,
                // Notice cannot be given for illness; a document can be asked for.
                'requires_document' => true,
                'sort' => 30,
            ],
            [
                'code' => 'unpaid',
                'label' => 'Unpaid Leave',
                'kind' => 'unpaid',
                // The only seeded type that can ever reduce pay, and only once the
                // payroll join is switched on: an unpaid day writes lop_days, which
                // is the one column pro-rating may read. docs/hrms-plan.md §11.
                'is_paid' => false,
                'accrual_method' => LeaveType::ACCRUAL_NONE,
                'days_per_year' => null,
                'sort' => 40,
            ],
            [
                'code' => 'maternity',
                'label' => 'Maternity Leave',
                'kind' => 'maternity',
                // Provincially set, and the figure most likely to be wrong for any
                // given company. Sindh legislates 16 weeks; other provinces differ.
                'days_per_year' => 112,
                'accrual_method' => LeaveType::ACCRUAL_ON_COMPLETION,
                'requires_document' => true,
                'allows_half_day' => false,
                'sort' => 50,
            ],
            [
                'code' => 'paternity',
                'label' => 'Paternity Leave',
                'kind' => 'paternity',
                'days_per_year' => 30,
                'accrual_method' => LeaveType::ACCRUAL_ON_COMPLETION,
                'allows_half_day' => false,
                'sort' => 60,
            ],
            [
                'code' => 'bereavement',
                'label' => 'Bereavement Leave',
                'kind' => 'bereavement',
                'days_per_year' => 3,
                'min_notice_days' => 0,
                'sort' => 70,
            ],
            [
                'code' => 'hajj',
                'label' => 'Hajj Leave',
                'kind' => 'hajj',
                // Once in a career for most people, and granted rather than accrued
                // annually — hence on completion of service rather than upfront.
                'days_per_year' => 30,
                'accrual_method' => LeaveType::ACCRUAL_ON_COMPLETION,
                'allows_half_day' => false,
                'min_notice_days' => 30,
                'sort' => 80,
            ],
        ];

        foreach ($types as $type) {
            LeaveType::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
