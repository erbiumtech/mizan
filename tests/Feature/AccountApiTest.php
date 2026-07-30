<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\AccountingTestCase;

class AccountApiTest extends AccountingTestCase
{
    private function actingAsRole(string $role): User
    {
        $user = $this->makeUser($role, strtolower($role).'-api@test.local');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_lists_accounts_with_filters(): void
    {
        $this->actingAsRole('Accountant');

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.code', '1000');

        $response = $this->getJson('/api/accounts?type=expense&search=Salary');
        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertContains('5100', $codes->all());
        $this->assertNotContains('1100', $codes->all());
    }

    public function test_tree_returns_nested_chart(): void
    {
        $this->actingAsRole('Accountant');

        $response = $this->getJson('/api/accounts/tree')->assertOk();

        $roots = collect($response->json('data'));
        $this->assertSame(['1000', '2000', '3000', '4000', '5000'], $roots->pluck('code')->all());

        $expenses = $roots->firstWhere('code', '5000');
        $this->assertContains('5100', collect($expenses['children'])->pluck('code')->all());
    }

    public function test_show_includes_parent_and_children(): void
    {
        $this->actingAsRole('Accountant');

        $group = Account::where('code', '5000')->firstOrFail();

        $this->getJson("/api/accounts/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.code', '5000')
            ->assertJsonStructure(['data' => ['children', 'children_count', 'lines_count'], 'lines']);
    }

    public function test_accountant_can_create_account(): void
    {
        $this->actingAsRole('Accountant');

        $parent = Account::where('code', '5000')->firstOrFail();

        $this->postJson('/api/accounts', [
            'code' => '5950',
            'name' => 'Training Expense',
            'type' => 'expense',
            'parent_id' => $parent->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', '5950')
            ->assertJsonPath('data.normal_balance', 'debit');
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->actingAsRole('Accountant');

        $this->postJson('/api/accounts', [
            'code' => '5100',
            'name' => 'Duplicate',
            'type' => 'expense',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_parent_type_mismatch_is_rejected(): void
    {
        $this->actingAsRole('Accountant');

        $assetGroup = Account::where('code', '1000')->firstOrFail();

        $this->postJson('/api/accounts', [
            'code' => '5960',
            'name' => 'Wrong Parent',
            'type' => 'expense',
            'parent_id' => $assetGroup->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_reparenting_under_own_descendant_is_rejected(): void
    {
        $this->actingAsRole('Accountant');

        $group = Account::where('code', '5000')->firstOrFail();
        $leaf = Account::where('code', '5100')->firstOrFail();

        $this->putJson("/api/accounts/{$group->id}", ['parent_id' => $leaf->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_accountant_cannot_delete_but_ceo_can(): void
    {
        $spare = Account::create(['code' => '5999', 'name' => 'Spare', 'type' => 'expense']);

        $this->actingAsRole('Accountant');
        $this->deleteJson("/api/accounts/{$spare->id}")->assertForbidden();

        $this->actingAsRole('CEO');
        $this->deleteJson("/api/accounts/{$spare->id}")->assertOk();
        $this->assertNull(Account::find($spare->id));
    }

    public function test_account_with_children_cannot_be_deleted(): void
    {
        $this->actingAsRole('CEO');

        $group = Account::where('code', '5000')->firstOrFail();

        $this->deleteJson("/api/accounts/{$group->id}")->assertForbidden();
    }

    public function test_employee_is_forbidden(): void
    {
        $this->actingAsRole('Employee');

        $this->getJson('/api/accounts')->assertForbidden();
        $this->getJson('/api/accounts/tree')->assertForbidden();
        $this->postJson('/api/accounts', ['code' => 'X', 'name' => 'X', 'type' => 'asset'])->assertForbidden();
    }
}
