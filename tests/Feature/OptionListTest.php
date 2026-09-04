<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\OptionValues\OptionValueResource;
use App\Modules\Core\Filament\Resources\OptionValues\Pages\CreateOptionValue;
use App\Modules\Core\Filament\Resources\OptionValues\Pages\ListOptionValues;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\OptionValue;
use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Filament\Resources\Vacancies\Pages\EditVacancy;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\OptionLists;
use Database\Seeders\OptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The dropdowns a company writes for itself — App\Support\OptionLists.
 *
 * Four things have to hold, and each is a way this feature could be worse than the
 * hardcoded arrays it replaces: a dropdown must never come out empty, an entry withdrawn
 * later must not silently blank the records already using it, renaming must not
 * reclassify, and a company must not be offered the lists of a module it has not bought.
 */
class OptionListTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        OptionLists::flush();
    }

    private function license(Company $company, string $module, bool $on = true): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    public function test_a_list_with_no_rows_still_answers_with_its_declared_defaults(): void
    {
        // The failure this guards: an unprovisioned or half-seeded tenant renders an
        // employee form whose designation dropdown is empty, and nobody can be hired.
        $this->assertSame([], OptionValue::all()->all());
        $this->assertArrayHasKey('Cook', options('employees.designation'));
    }

    public function test_the_seeder_writes_the_values_the_forms_used_to_hardcode(): void
    {
        (new OptionListSeeder)->run();

        $this->assertSame(
            ['permanent' => 'Permanent', 'contract' => 'Contract', 'probation' => 'Probation', 'intern' => 'Intern'],
            options('employees.employment_type'),
        );

        // Re-running tops up rather than duplicating or resetting.
        OptionValue::where('list', 'employees.designation')->where('value', 'Cook')->update(['label' => 'Chef']);
        OptionLists::flush();
        (new OptionListSeeder)->run();
        OptionLists::flush();

        $this->assertSame('Chef', options('employees.designation')['Cook']);
        $this->assertSame(1, OptionValue::where('list', 'employees.designation')->where('value', 'Cook')->count());
    }

    public function test_an_admin_adding_an_entry_changes_what_the_dropdown_offers(): void
    {
        (new OptionListSeeder)->run();
        OptionLists::flush();

        OptionValue::create(['list' => 'employees.department', 'label' => 'Site', 'sort' => 5]);
        OptionLists::flush();

        $options = options('employees.department');

        $this->assertSame('Site', $options['Site'], 'A new entry stores its label as the value.');
        $this->assertSame('Site', array_key_first($options), 'Order in the list is the sort column.');
    }

    public function test_relabelling_does_not_move_the_records_already_saved(): void
    {
        (new OptionListSeeder)->run();

        $cook = OptionValue::where('list', 'employees.designation')->where('value', 'Cook')->firstOrFail();
        $cook->update(['label' => 'Chef', 'value' => 'Chef']);
        OptionLists::flush();

        // The value is fixed at creation *even when something tries to change it*: every
        // employee row already reads "Cook", and rewriting it here orphans all of them.
        $this->assertSame('Cook', $cook->fresh()->value);
        $this->assertSame(['Cook' => 'Chef'], array_intersect_key(options('employees.designation'), ['Cook' => '']));
    }

    public function test_a_withdrawn_entry_stays_visible_on_the_record_that_holds_it(): void
    {
        (new OptionListSeeder)->run();

        OptionValue::where('list', 'employees.designation')->where('value', 'Cook')->update(['is_active' => false]);
        OptionLists::flush();

        $this->assertArrayNotHasKey('Cook', options('employees.designation'));

        // What the form passes for the record being edited. Without it the Select renders
        // blank and writes that blank back on the next save.
        $this->assertArrayHasKey('Cook', options('employees.designation', 'Cook'));
    }

    public function test_a_company_is_only_offered_the_lists_of_the_modules_it_has(): void
    {
        $this->actingAs(User::factory()->create());

        $company = Company::factory()->create();
        $this->setCurrentTenant($company);

        foreach (['employees', 'construction_qhse'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => $module === 'employees', 'enabled' => $module === 'employees'],
            );
        }

        modules()->flush();

        $enabled = OptionLists::enabled();

        $this->assertArrayHasKey('employees.designation', $enabled);
        $this->assertArrayNotHasKey('construction_qhse.ncr_category', $enabled);

        // And the rows of the unlicensed list are out of the screen that edits them —
        // kept in the table, because a licence can come back.
        (new OptionListSeeder)->run();

        $this->assertGreaterThan(0, OptionValue::where('list', 'construction_qhse.ncr_category')->count());
        $this->assertSame(
            [],
            OptionValueResource::getEloquentQuery()->where('list', 'construction_qhse.ncr_category')->pluck('id')->all(),
        );
    }

    public function test_a_value_written_before_the_list_existed_survives_an_edit(): void
    {
        // The Vacancy screen asked for these three as free text until now, so real rows
        // hold words no list contains. A Select whose current value is not among its
        // options renders blank and saves that blank back — losing a field nobody
        // touched, on a screen somebody opened to change something else.
        Gate::before(fn () => true);

        $this->actingAs(User::factory()->create());
        $company = Company::factory()->create();
        $this->setCurrentTenant($company);

        $this->license($company, 'recruitment');

        (new OptionListSeeder)->run();
        OptionLists::flush();

        $vacancy = Vacancy::create([
            'code' => 'VAC-1',
            'title' => 'Somebody who writes PHP',
            'status' => Vacancy::STATUS_OPEN,
            'designation' => 'backend dev',
        ]);

        Livewire::test(EditVacancy::class, ['record' => $vacancy->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet(['designation' => 'backend dev'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('backend dev', $vacancy->fresh()->designation);
    }

    public function test_re_adding_an_entry_that_is_only_switched_off_says_so(): void
    {
        // The table has a unique index on (list, value). Somebody who cannot see "Cook"
        // in the list types it again, and without the rule behind this that is a 500.
        $company = Company::factory()->create();
        $this->setCurrentTenant($this->administratorOf($company));
        $this->license($company, 'employees');

        (new OptionListSeeder)->run();
        OptionValue::where('list', 'employees.designation')->where('value', 'Cook')->update(['is_active' => false]);
        OptionLists::flush();

        Livewire::test(CreateOptionValue::class)
            ->fillForm(['list' => 'employees.designation', 'label' => 'Cook'])
            ->call('create')
            ->assertHasFormErrors(['label']);

        $this->assertSame(1, OptionValue::where('list', 'employees.designation')->where('value', 'Cook')->count());
    }

    public function test_the_screen_renders_and_shows_the_lists_this_company_has(): void
    {
        // Not covered by FilamentResourcesSmokeTest, which skips resources it cannot
        // reach and this one is administrators only.
        $company = Company::factory()->create();
        $this->setCurrentTenant($this->administratorOf($company));

        $this->license($company, 'employees');
        $this->license($company, 'construction_qhse', false);

        (new OptionListSeeder)->run();

        // Filtered to one list rather than read off the first page. Unfiltered, this asserted on
        // pagination by accident: every list a module adds pushes the rows it was looking for further
        // down, and declaring `employees.conduct` — which sorts ahead of `employees.designation` — is
        // what proved it by moving "Cook" onto page two.
        Livewire::test(ListOptionValues::class)
            ->assertSuccessful()
            ->filterTable('list', 'employees.designation')
            ->assertCanSeeTableRecords(
                OptionValue::where('list', 'employees.designation')->orderBy('sort')->get()
            )
            ->assertSee('Cook')
            ->assertDontSee('Workmanship');
    }

    /**
     * The screen accounts for the dropdowns it does not itself edit.
     *
     * The question that prompted this was "where do I update petty cash categories" — a dropdown that was
     * always editable, on a screen nobody could be expected to guess: a petty cash category *is* a
     * transaction type. Not finding it here looked like it could not be changed at all.
     */
    public function test_a_dropdown_edited_elsewhere_is_pointed_at_rather_than_missing(): void
    {
        $company = Company::factory()->create();
        $this->setCurrentTenant($this->administratorOf($company));
        $this->license($company, 'accounting');

        $pointers = OptionLists::elsewhere();

        $this->assertArrayHasKey('accounting.transaction_type', $pointers);
        $this->assertStringContainsString(
            'petty cash',
            strtolower($pointers['accounting.transaction_type']['label'].$pointers['accounting.transaction_type']['help']),
        );
        $this->assertStringContainsString('transaction-types', $pointers['accounting.transaction_type']['url']);

        // And a pointer is not an editable list: it must never appear as a choice on the form here, or
        // somebody would file a transaction type as a word with no account behind it.
        $this->assertArrayNotHasKey('accounting.transaction_type', OptionLists::all());
    }

    /** A pointer to a screen this company has not licensed is left out rather than rendered as a dead link. */
    public function test_a_pointer_is_hidden_when_its_module_is_off(): void
    {
        $company = Company::factory()->create();
        $this->setCurrentTenant($this->administratorOf($company));
        $this->license($company, 'accounting', false);

        $this->assertArrayNotHasKey('accounting.transaction_type', OptionLists::elsewhere());
    }

    public function test_only_an_administrator_reaches_the_screen(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->setCurrentTenant();

        $this->assertFalse(OptionValueResource::canAccess());

        Role::findOrCreate('Administrator', 'web');
        $user->assignRole('Administrator');

        $this->assertTrue(OptionValueResource::canAccess());
    }

    /**
     * An administrator of this company, signed in.
     *
     * The role is assigned under the company's own permission team, which is why the
     * company has to exist first — and why this hands it back, for setCurrentTenant().
     */
    private function administratorOf(Company $company): Company
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        Role::findOrCreate('Administrator', 'web');

        $user = User::factory()->create();
        $company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);

        return $company;
    }
}
