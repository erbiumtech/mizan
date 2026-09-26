<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Employee names must not cost a query per row.
 *
 * `display_label` resolves through `Employee::fullName()`, which reads `user` — and
 * `fullName()`'s `loadMissing` made that *legal* under `preventLazyLoading` while
 * staying one query per employee down a list. So the lazy-loading guard cannot catch
 * a regression here; only counting queries can, which is what this does. The fix is
 * `Employee::$with = ['user']`: the user rides along on the employees query itself
 * (docs/page-load-performance-plan.md, "Still outstanding").
 *
 * Two employees at minimum in every fixture on purpose: a one-row list multiplies a
 * per-row query by nothing and proves nothing.
 */
class EmployeeUserEagerLoadTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament::setTenant() dispatches TenantSet with the authenticated user;
        // without one it type-errors on null, so log in before taking the tenant.
        $this->actingAs(User::factory()->create());

        $company = $this->setCurrentTenant();
        app()->instance('currentTenant', $company);
    }

    public function test_reading_every_name_costs_the_same_queries_at_any_row_count(): void
    {
        $this->employees(2);
        $few = $this->queriesToReadAllNames();

        $this->employees(6);
        $many = $this->queriesToReadAllNames();

        $this->assertSame(
            count($few),
            count($many),
            "reading names over eight employees ran more queries than over two, so the name is per-row again:\n\n"
            .implode("\n", array_diff($many, $few)),
        );
    }

    public function test_the_eager_loaded_user_stays_out_of_serialization(): void
    {
        $this->employees(2);

        $employee = Employee::query()->first();

        // The relation is there for the panel's names…
        $this->assertTrue($employee->relationLoaded('user'));

        // …and absent from JSON: the profile API returned bare employee attributes
        // before `$with`, and must keep doing so (Employee::$hidden guards this).
        $this->assertArrayNotHasKey('user', $employee->toArray());
    }

    private function employees(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Employee::create([
                'user_id' => User::factory()->create()->id,
                'employee_id' => Employee::nextEmployeeId(),
                'phone' => '111',
                'gender' => 'Male',
                'is_active' => 1,
                'nic' => '123',
            ]);
        }
    }

    /**
     * The statements it takes to render every employee's name — the shape of the
     * fourteen `employee.display_label` table columns and of every employee select.
     *
     * A fresh listener per call collecting into its own array, same as
     * PanelPerformanceTest: Laravel has no way to remove a listener.
     *
     * @return array<int, string>
     */
    private function queriesToReadAllNames(): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Employee::query()->get()->each(fn (Employee $employee) => $employee->fullName());

        return $queries;
    }
}
