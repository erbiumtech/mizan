<?php

namespace Tests\Feature;

use App\Events\UserCreated;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeCodeRenumbering;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Employee codes belong to the company, not to the platform.
 *
 * `employees` is a tenant table with no `company_id` — each company has its own
 * database — so a code taken from the employees already in it is per-company by
 * construction. The code was instead being built from the *user* id, and users are
 * landlord records shared across every company: one global counter numbered them all,
 * so no company's codes started at one or ran consecutively.
 *
 * Cross-company isolation itself is a property of the per-tenant connection and cannot
 * be asserted here — `TenantModel` falls back to the default connection under test, so
 * the whole suite shares one database. What these tests pin down is the sequence: where
 * it starts, what it counts from, and what it ignores.
 */
class EmployeeCodeSequenceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
    }

    private function makeEmployee(string $employeeId, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => $employeeId,
            'name' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
        ], $attributes));
    }

    /** THE test: the bug was that this returned the user's id. */
    public function test_a_companys_first_code_is_one_and_not_the_landlord_user_id(): void
    {
        // Push the id well clear of 1 so a code derived from it is unmistakable.
        User::factory()->count(5)->create(['status' => 1]);
        $user = User::factory()->create(['status' => 1]);

        $this->assertGreaterThan(1, $user->getKey(), 'the fixture must not sit on id 1');

        UserCreated::dispatch($user);

        $employee = Employee::where('user_id', $user->getKey())->sole();

        $this->assertSame('EMP-0001', $employee->employee_id);
        $this->assertNotSame('EMP-'.$user->getKey(), $employee->employee_id);
    }

    public function test_the_sequence_continues_from_the_highest_code_in_use(): void
    {
        $this->makeEmployee('EMP-0001');
        $this->makeEmployee('EMP-0002');

        $this->assertSame('EMP-0003', Employee::nextEmployeeId());
    }

    /**
     * The reason this reads the highest code rather than counting the rows.
     *
     * A count drops when somebody leaves and is deleted, so the next hire was handed a
     * code an existing row still held — and `employee_id` is unique, so that hire failed
     * outright rather than merely duplicating a number.
     *
     * Note the guarantee being made: the next code never collides with one still in use.
     * A code freed by deleting the *highest* employee is reused, which is why this asserts
     * against a gap in the middle — the case the old count-based code got wrong.
     */
    public function test_the_next_code_never_collides_with_one_still_in_use(): void
    {
        $this->makeEmployee('EMP-0001');
        $this->makeEmployee('EMP-0002');
        $this->makeEmployee('EMP-0003');

        Employee::where('employee_id', 'EMP-0002')->delete();

        // Two rows remain, so a count would propose EMP-0003 — which EMP-0003 holds.
        $this->assertSame(2, Employee::count());
        $this->assertSame('EMP-0004', Employee::nextEmployeeId());
        $this->assertFalse(
            Employee::where('employee_id', Employee::nextEmployeeId())->exists(),
            'the proposed code must not already be taken'
        );
    }

    /** Existing data mixes widths, and by string order `EMP-9` sorts above `EMP-10`. */
    public function test_codes_of_differing_width_are_compared_numerically(): void
    {
        $this->makeEmployee('EMP-9');
        $this->makeEmployee('EMP-10');

        $this->assertSame('EMP-0011', Employee::nextEmployeeId());
    }

    /** A company's own hand-typed codes must not perturb the sequence. */
    public function test_codes_that_are_not_the_prefix_followed_by_digits_are_ignored(): void
    {
        $this->makeEmployee('STAFF-7');
        $this->makeEmployee('EMP-A1');
        $this->makeEmployee('EMP-2024-CONTRACT');

        $this->assertSame('EMP-0001', Employee::nextEmployeeId());
    }

    public function test_an_empty_company_starts_at_one(): void
    {
        $this->assertSame(0, Employee::count());
        $this->assertSame('EMP-0001', Employee::nextEmployeeId());
    }

    public function test_a_company_may_use_its_own_prefix(): void
    {
        $this->makeEmployee('STAFF-0003');

        $this->assertSame('STAFF-0004', Employee::nextEmployeeId('STAFF-'));
        // The default sequence is unaffected by codes under another prefix.
        $this->assertSame('EMP-0001', Employee::nextEmployeeId());
    }

    /**
     * The renumbering itself is exercised through the service rather than the command:
     * the command is `TenantAware`, and switching tenant databases is the one thing this
     * suite cannot do, because it runs every tenant in a single database.
     */
    private function renumber(string $prefix = 'EMP-'): int
    {
        $service = app(EmployeeCodeRenumbering::class);
        $plan = $service->plan($prefix);

        return $service->apply(
            $plan['mapping']->filter(fn (array $row): bool => $row['from'] !== $row['to'])->values()
        );
    }

    /** `date_of_joining` is a date cast, so it is stored with a time and needs whereDate. */
    private function codeFor(string $joinedOn): ?string
    {
        return Employee::whereDate('date_of_joining', $joinedOn)->value('employee_id');
    }

    public function test_renumbering_gives_the_lowest_code_to_the_longest_serving(): void
    {
        $this->makeEmployee('EMP-38', ['date_of_joining' => '2020-01-15']);
        $this->makeEmployee('EMP-20', ['date_of_joining' => '2024-06-01']);
        $this->makeEmployee('EMP-31', ['date_of_joining' => '2022-03-10']);

        $this->assertSame(3, $this->renumber());

        // Ordered by service, not by the code they happened to hold.
        $this->assertSame('EMP-0001', $this->codeFor('2020-01-15'));
        $this->assertSame('EMP-0002', $this->codeFor('2022-03-10'));
        $this->assertSame('EMP-0003', $this->codeFor('2024-06-01'));
    }

    /**
     * The case a one-pass rewrite cannot survive.
     *
     * These two must swap codes. Updating them in order assigns EMP-0001 to the first
     * while the second still holds it, and `employee_id` is unique — so the whole
     * renumber would fail on a perfectly ordinary pair.
     */
    public function test_renumbering_can_swap_two_codes(): void
    {
        $this->makeEmployee('EMP-0002', ['date_of_joining' => '2020-01-01']);
        $this->makeEmployee('EMP-0001', ['date_of_joining' => '2023-01-01']);

        $this->assertSame(2, $this->renumber());

        $this->assertSame('EMP-0001', $this->codeFor('2020-01-01'));
        $this->assertSame('EMP-0002', $this->codeFor('2023-01-01'));
    }

    public function test_renumbering_leaves_hand_typed_codes_alone(): void
    {
        $this->makeEmployee('STAFF-7', ['date_of_joining' => '2019-01-01']);
        $this->makeEmployee('EMP-2024-CONTRACT', ['date_of_joining' => '2019-06-01']);
        $this->makeEmployee('EMP-38', ['date_of_joining' => '2020-01-01']);

        $plan = app(EmployeeCodeRenumbering::class)->plan('EMP-');

        $this->assertEqualsCanonicalizing(
            ['STAFF-7', 'EMP-2024-CONTRACT'],
            $plan['skipped']->all(),
            'a code that is not the prefix followed by digits was meant, and is reported as skipped'
        );

        $this->renumber();

        $this->assertTrue(Employee::where('employee_id', 'STAFF-7')->exists());
        $this->assertTrue(Employee::where('employee_id', 'EMP-2024-CONTRACT')->exists());
        // Only the generated code is renumbered, and it starts the sequence at one.
        $this->assertSame('EMP-0001', $this->codeFor('2020-01-01'));
    }

    public function test_planning_writes_nothing(): void
    {
        $this->makeEmployee('EMP-38', ['date_of_joining' => '2020-01-01']);

        app(EmployeeCodeRenumbering::class)->plan('EMP-');

        $this->assertSame('EMP-38', $this->codeFor('2020-01-01'));
    }

    public function test_renumbering_an_already_sequential_company_changes_nothing(): void
    {
        $this->makeEmployee('EMP-0001', ['date_of_joining' => '2020-01-01']);
        $this->makeEmployee('EMP-0002', ['date_of_joining' => '2021-01-01']);

        $this->assertSame(0, $this->renumber());

        $this->assertSame('EMP-0001', $this->codeFor('2020-01-01'));
        $this->assertSame('EMP-0002', $this->codeFor('2021-01-01'));
    }

    /** After renumbering, the next code must continue the sequence rather than collide. */
    public function test_the_sequence_resumes_correctly_after_renumbering(): void
    {
        $this->makeEmployee('EMP-38', ['date_of_joining' => '2020-01-01']);
        $this->makeEmployee('EMP-20', ['date_of_joining' => '2021-01-01']);

        $this->renumber();

        $this->assertSame('EMP-0003', Employee::nextEmployeeId());
    }

    /**
     * The race: reading the highest code and inserting the next one are two steps, so two
     * people hired at the same instant both read the same highest and the second insert
     * hits the unique index.
     *
     * Simulated deterministically rather than with threads — the callback itself takes the
     * code it was handed, exactly as a competing writer would, and only on the first pass.
     */
    public function test_a_code_taken_between_the_read_and_the_insert_is_regenerated(): void
    {
        $attempts = 0;

        $employee = Employee::withGeneratedCode(function (string $code) use (&$attempts): Employee {
            $attempts++;

            if ($attempts === 1) {
                // Somebody else banks this code in the gap.
                $this->makeEmployee($code);
            }

            return $this->makeEmployee($code);
        });

        $this->assertSame(2, $attempts, 'it should have looked again exactly once');
        $this->assertSame('EMP-0001', Employee::find(1)?->employee_id);
        $this->assertSame('EMP-0002', $employee->employee_id, 'the retry takes the next code, not the taken one');
    }

    public function test_an_uncontended_insert_is_not_retried(): void
    {
        $attempts = 0;

        $employee = Employee::withGeneratedCode(function (string $code) use (&$attempts): Employee {
            $attempts++;

            return $this->makeEmployee($code);
        });

        $this->assertSame(1, $attempts);
        $this->assertSame('EMP-0001', $employee->employee_id);
    }

    /** Bounded, so a cause that is not a race surfaces instead of spinning. */
    public function test_it_gives_up_rather_than_retrying_forever(): void
    {
        $attempts = 0;

        $alwaysCollides = function (string $code) use (&$attempts): Employee {
            $attempts++;
            $this->makeEmployee($code);

            return $this->makeEmployee($code);
        };

        try {
            Employee::withGeneratedCode($alwaysCollides, attempts: 3);
            $this->fail('a permanently contended code should surface, not be swallowed');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(3, $attempts);
        }
    }
}
