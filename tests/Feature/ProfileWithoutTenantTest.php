<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The profile page has no tenant, and asking about employees there used to be a 500.
 *
 * `/admin/profile` is a panel route with no `{tenant}` segment, so spatie never makes a company
 * current and the `tenant` connection is still pointing at the placeholder database. Anything that
 * reads a tenant table from there fails with *no such table*, and the profile page reads one twice:
 * once to decide whether the name is managed by an employee record, and once — through
 * `ConfirmDetails::canAccess()` — to decide whether to offer the *Confirm your details* button.
 *
 * The first had a guard from the day it was written; the second did not, and reported:
 *
 *     SQLSTATE[HY000]: General error: 1 no such table: employees
 *     (Connection: tenant, Database: .../database/tenants/placeholder.sqlite)
 *
 * The guard is in `EmployeeAccess` rather than on the two callers, because every method there reads
 * a tenant table and the next tenant-less caller should not have to rediscover this.
 */
class ProfileWithoutTenantTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /**
     * The question itself, with nobody having said which company.
     *
     * Asserted before the page, because this is the whole of the fix: outside a company there is no
     * employee record to find, so the answer is no rather than an exception.
     */
    public function test_asking_whether_somebody_is_an_employee_outside_a_company_says_no(): void
    {
        $user = $this->makeUser('Administrator', 'tenantless@test.local');
        $this->actingAs($user);

        // What a tenant-less panel route looks like: no company has been made current.
        Company::forgetCurrent();

        $this->assertNull(Company::current(), 'the fixture itself must be tenant-less, or this proves nothing');

        // With no employee row and no company, the answer is no — and it is reached without an
        // exception, which is the whole of it.
        $this->assertFalse(app(EmployeeAccess::class)->isEmployee($user));
        $this->assertNull(app(EmployeeAccess::class)->employeeFor($user));
        $this->assertNull(app(EmployeeAccess::class)->ownEmployeeId($user));
    }

    /** And inside one it still answers properly, which is what makes the guard a guard and not a mute. */
    public function test_inside_a_company_it_still_finds_the_employee(): void
    {
        $user = $this->makeUser('Administrator', 'inside@test.local');
        $this->actingAs($user);
        $this->setCurrentTenant();

        $employee = Employee::create([
            'employee_id' => 'EMP-0001',
            'user_id' => $user->getKey(),
            'nic' => '3520212345671',
            'phone' => '03001234567',
            'secondary_phone' => '03007654321',
            'is_active' => true,
        ]);

        $this->assertTrue(app(EmployeeAccess::class)->isEmployee($user));
        $this->assertSame((int) $employee->getKey(), app(EmployeeAccess::class)->ownEmployeeId($user));
    }

    /**
     * The failure itself, which the suite cannot otherwise produce.
     *
     * **In the test environment there is no separate tenant database.** `multitenancy
     * .tenant_database_connection_name` is null, so `TenantModel` falls back to the default
     * connection and `employees` exists whether or not a company is current — which means the two
     * tests above pass with the guard removed. They assert the behaviour; they do not reproduce the
     * bug, and a test that cannot fail is worth saying so about.
     *
     * This one points the tenant connection at an empty database, which is what production had:
     * `placeholder.sqlite`, with no tables in it. Without the guard this throws *no such table:
     * employees*; with it, nothing is asked at all.
     */
    public function test_nothing_is_asked_of_a_tenant_database_that_has_no_tables(): void
    {
        $user = $this->makeUser('Administrator', 'placeholder@test.local');
        $this->actingAs($user);

        Company::forgetCurrent();

        // An empty database standing in for the placeholder one a tenant-less request lands on.
        config([
            'database.connections.placeholder_tenant' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'multitenancy.tenant_database_connection_name' => 'placeholder_tenant',
        ]);

        $this->assertFalse(
            app(EmployeeAccess::class)->isEmployee($user),
            'it asked the placeholder database, which has no employees table',
        );
    }

    /**
     * The page the bug was reported from.
     *
     * A GET rather than a Livewire test on purpose: the failure was in a header action evaluated
     * while the page mounted, which is a path only a real request exercises. It cannot reproduce
     * the original 500 either — see the test above for why — but it does catch the page breaking
     * for any other reason once the tenant is gone.
     */
    public function test_the_profile_page_opens_without_a_tenant(): void
    {
        $this->actingAs($this->makeUser('Administrator', 'profile@test.local'));

        Company::forgetCurrent();

        $this->get('/admin/profile')->assertSuccessful();
    }
}
