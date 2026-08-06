<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Services\PersonalEntryService;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The personal ledger: that it is a real double-entry ledger and not just two
 * numbers in a row, and that the three everyday verbs build the right pair of
 * lines.
 */
class PersonalLedgerTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->seed([PermissionSeeder::class, FiscalYearSeeder::class]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $this->user = User::factory()->create(['status' => 1]);
        $company->users()->attach($this->user->getKey());
        $this->user->assignRole('Employee');

        $this->actingAs($this->user);
        $this->setCurrentTenant($company);
    }

    private function account(string $code, string $name, string $type, float $opening = 0): PersonalAccount
    {
        return PersonalAccount::create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'opening_balance' => $opening,
        ]);
    }

    private function service(): PersonalEntryService
    {
        return app(PersonalEntryService::class);
    }

    public function test_recording_income_debits_the_account_and_credits_the_category(): void
    {
        $bank = $this->account('1100', 'Bank', PersonalAccount::TYPE_ASSET);
        $salary = $this->account('4000', 'Salary', PersonalAccount::TYPE_INCOME);

        $entry = $this->service()->recordIncome($bank, $salary, 187500, [
            'description' => 'August salary',
        ]);

        $this->assertCount(2, $entry->lines);
        $this->assertTrue($entry->isBalanced());

        $this->assertSame(187500.0, (float) $entry->lines->firstWhere('personal_account_id', $bank->id)->debit);
        $this->assertSame(187500.0, (float) $entry->lines->firstWhere('personal_account_id', $salary->id)->credit);

        $this->assertSame(187500.0, $bank->fresh()->balance());
    }

    public function test_recording_an_expense_moves_money_out(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET, 50000);
        $education = $this->account('5300', 'Education', PersonalAccount::TYPE_EXPENSE);

        $this->service()->recordExpense($education, $cash, 12000, ['description' => 'School fees']);

        // Cash is debit-normal, so a credit reduces it.
        $this->assertSame(38000.0, $cash->fresh()->balance());
        // Expense is debit-normal, so it grows on the debit.
        $this->assertSame(12000.0, $education->fresh()->balance());
    }

    public function test_a_transfer_leaves_net_worth_unchanged(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET, 100000);
        $bank = $this->account('1100', 'Bank', PersonalAccount::TYPE_ASSET, 0);

        $before = $cash->balance() + $bank->balance();

        $this->service()->transfer($cash, $bank, 40000, ['description' => 'Deposit']);

        $this->assertSame(60000.0, $cash->fresh()->balance());
        $this->assertSame(40000.0, $bank->fresh()->balance());
        $this->assertSame($before, $cash->fresh()->balance() + $bank->fresh()->balance());
    }

    public function test_an_unbalanced_entry_is_refused(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET);
        $food = $this->account('5100', 'Food', PersonalAccount::TYPE_EXPENSE);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not balance');

        $this->service()->create(['description' => 'Wrong'], [
            ['account_id' => $food->id, 'debit' => 500],
            ['account_id' => $cash->id, 'credit' => 400],
        ]);
    }

    public function test_a_line_cannot_be_both_a_debit_and_a_credit(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET);
        $food = $this->account('5100', 'Food', PersonalAccount::TYPE_EXPENSE);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        $this->service()->create(['description' => 'Wrong'], [
            ['account_id' => $food->id, 'debit' => 500, 'credit' => 500],
            ['account_id' => $cash->id, 'credit' => 500],
        ]);
    }

    public function test_an_entry_needs_two_lines(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least two lines');

        $this->service()->create(['description' => 'Lonely'], [
            ['account_id' => $cash->id, 'debit' => 100],
        ]);
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET);
        $food = $this->account('5100', 'Food', PersonalAccount::TYPE_EXPENSE);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than zero');

        $this->service()->recordExpense($food, $cash, 0);
    }

    public function test_a_closed_account_cannot_be_posted_to(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET);
        $old = $this->account('5900', 'Old category', PersonalAccount::TYPE_EXPENSE);
        $old->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('closed');

        $this->service()->recordExpense($old, $cash, 100);
    }

    public function test_fractional_amounts_still_balance(): void
    {
        // 0.1 + 0.2 !== 0.3 in binary floating point. A ledger that refuses a
        // correct entry over that is worse than a slow one, hence the string
        // comparison in the service.
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET);
        $a = $this->account('5100', 'A', PersonalAccount::TYPE_EXPENSE);
        $b = $this->account('5200', 'B', PersonalAccount::TYPE_EXPENSE);

        $entry = $this->service()->create(['description' => 'Split'], [
            ['account_id' => $a->id, 'debit' => 0.10],
            ['account_id' => $b->id, 'debit' => 0.20],
            ['account_id' => $cash->id, 'credit' => 0.30],
        ]);

        $this->assertTrue($entry->isBalanced());
    }

    public function test_the_entry_lands_in_the_fiscal_year_covering_its_date(): void
    {
        $cash = $this->account('1000', 'Cash', PersonalAccount::TYPE_ASSET);
        $food = $this->account('5100', 'Food', PersonalAccount::TYPE_EXPENSE);

        $entry = $this->service()->recordExpense($food, $cash, 500, [
            'date' => '2025-09-15',
            'description' => 'Groceries',
        ]);

        $this->assertNotNull($entry->fiscal_year_id);
        $this->assertSame('2025-2026', $entry->fiscalYear->name);
    }
}
