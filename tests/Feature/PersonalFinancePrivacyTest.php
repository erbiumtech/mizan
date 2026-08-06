<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\PersonalEntry;
use App\Modules\PersonalFinance\Services\PersonalEntryService;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The test this module exists or fails on.
 *
 * Two separate promises, and they fail in different ways:
 *
 *  1. One person cannot reach another's books. Note this cannot be a policy —
 *     Gate::before hands Administrators every ability but `create`, so a policy
 *     ownership check never runs for them. It is a global scope plus a
 *     save/delete guard, and these tests exercise an Administrator specifically
 *     because they are the case a policy would miss.
 *  2. Personal money never appears in the company's books. If it ever did,
 *     somebody's groceries would land in the company Profit & Loss.
 */
class PersonalFinancePrivacyTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private User $alice;

    private User $bob;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->seed([PermissionSeeder::class, FiscalYearSeeder::class]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->alice = $this->member('Employee');
        $this->bob = $this->member('Employee');
        $this->admin = $this->member('Administrator');
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($user->getKey());

        $current = auth()->user();
        $this->actingAs($user);
        $user->assignRole($role);
        $current ? $this->actingAs($current) : auth()->logout();

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        $this->setCurrentTenant($this->company);
    }

    /** Give a user one expense, and return the entry. */
    private function seedBooksFor(User $user, float $amount = 5000): PersonalEntry
    {
        $this->actAs($user);

        $cash = PersonalAccount::create([
            'code' => '1000', 'name' => 'Cash', 'type' => PersonalAccount::TYPE_ASSET,
            'opening_balance' => 100000,
        ]);
        $education = PersonalAccount::create([
            'code' => '5300', 'name' => 'Education', 'type' => PersonalAccount::TYPE_EXPENSE,
        ]);

        return app(PersonalEntryService::class)
            ->recordExpense($education, $cash, $amount, ['description' => 'School fees']);
    }

    public function test_one_person_cannot_see_anothers_accounts_or_entries(): void
    {
        $this->seedBooksFor($this->alice);

        $this->actAs($this->bob);

        $this->assertSame(0, PersonalAccount::count(), "Bob can see Alice's accounts.");
        $this->assertSame(0, PersonalEntry::count(), "Bob can see Alice's entries.");
    }

    public function test_a_direct_lookup_of_someone_elses_record_finds_nothing(): void
    {
        $entry = $this->seedBooksFor($this->alice);
        $accountId = PersonalAccount::first()->id;

        $this->actAs($this->bob);

        // The scope applies to find() too, so knowing the id is no help.
        $this->assertNull(PersonalEntry::find($entry->id));
        $this->assertNull(PersonalAccount::find($accountId));
    }

    public function test_an_administrator_cannot_see_someone_elses_books_by_default(): void
    {
        $this->seedBooksFor($this->alice);

        $this->actAs($this->admin);

        // The case a policy would get wrong: Gate::before would have said yes.
        $this->assertSame(0, PersonalAccount::count());
        $this->assertSame(0, PersonalEntry::count());
    }

    public function test_an_administrator_can_view_across_users_when_they_ask_explicitly(): void
    {
        $this->seedBooksFor($this->alice);

        $this->actAs($this->admin);

        $this->assertTrue($this->admin->can('PersonalFinanceViewAny'));
        $this->assertSame(1, PersonalEntry::ownedByAnyone()->count());
        $this->assertSame(2, PersonalAccount::ownedByAnyone()->count());
    }

    public function test_an_administrator_cannot_edit_someone_elses_records(): void
    {
        $this->seedBooksFor($this->alice);

        $this->actAs($this->admin);

        $entry = PersonalEntry::ownedByAnyone()->firstOrFail();

        // View yes, edit no — and this is the guard doing it, not the Gate,
        // which would have allowed it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("somebody else's personal records");

        $entry->update(['description' => 'Rewritten by the admin']);
    }

    public function test_an_administrator_cannot_delete_someone_elses_records(): void
    {
        $this->seedBooksFor($this->alice);

        $this->actAs($this->admin);

        $entry = PersonalEntry::ownedByAnyone()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("somebody else's personal records");

        $entry->delete();
    }

    public function test_an_owner_can_still_edit_and_delete_their_own(): void
    {
        $entry = $this->seedBooksFor($this->alice);

        $entry->update(['description' => 'Corrected']);
        $this->assertSame('Corrected', $entry->fresh()->description);

        $entry->delete();
        $this->assertNull(PersonalEntry::find($entry->id));
    }

    public function test_a_new_record_is_stamped_with_its_creator(): void
    {
        $this->actAs($this->alice);

        $account = PersonalAccount::create([
            'code' => '1000', 'name' => 'Cash', 'type' => PersonalAccount::TYPE_ASSET,
        ]);

        $this->assertSame($this->alice->id, $account->ownerId());
    }

    public function test_two_people_can_use_the_same_account_code(): void
    {
        // The company chart cannot do this: accounts.code is globally unique.
        // Here the uniqueness is per person, which is the point.
        $this->seedBooksFor($this->alice);
        $this->seedBooksFor($this->bob);

        $this->actAs($this->alice);
        $this->assertSame(1, PersonalAccount::where('code', '5300')->count());

        $this->actAs($this->bob);
        $this->assertSame(1, PersonalAccount::where('code', '5300')->count());

        $this->actAs($this->admin);
        $this->assertSame(2, PersonalAccount::ownedByAnyone()->where('code', '5300')->count());
    }

    public function test_personal_money_never_reaches_the_company_books(): void
    {
        $this->seedBooksFor($this->alice, 5000);

        $this->actAs($this->admin);

        // The company ledger is a different set of tables entirely, so a
        // personal expense cannot appear in the Trial Balance, the P&L, the
        // Balance Sheet or the Account Register — all of which read these.
        $this->assertSame(0, JournalEntryLine::count(), 'A personal expense reached the company ledger.');
        $this->assertSame(
            0,
            \App\Modules\Accounting\Models\JournalEntry::count(),
            'A personal entry created a company journal entry.',
        );
    }
}
