<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The construction account map becomes a choice from the chart — `docs/construction-management-plan.md` §18.2.
 *
 * Until now `ConstructionAccounts` read typed account *codes* from the `accounting.construction_accounts` setting and
 * fell back to shipped codes in `config/accounting.php`. One of those was wrong for every company on the standard
 * chart: goods received not invoiced defaulted to 2600, which `ChartOfAccountsSeeder` gives to Customer Advances, and
 * `ConstructionAccountsSeeder` then skipped creating the real account because the code was taken.
 *
 * The map is now `construction.accounts`, line key => account id, with nothing behind it at runtime. This writes it
 * for every company that already has the construction accounts, resolving each line the way it resolved yesterday —
 * the company's own saved code first, the shipped code second — except goods received not invoiced where it still
 * names 2600 and 2600 is not that account: that line gets the real account, created at a free code if it is missing.
 *
 * The shipped codes below are a frozen copy of what config said on the day, so this migration means the same thing
 * whenever it runs.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> line key => [shipped code, the name the seeder gave it] */
    private const SHIPPED = [
        'contract_revenue' => ['4600', 'Contract Revenue'],
        'contract_assets' => ['1610', 'Contract Assets'],
        'contract_liabilities' => ['2630', 'Contract Liabilities'],
        'retention_receivable' => ['1620', 'Retention Receivable'],
        'retention_payable' => ['2620', 'Retention Payable'],
        'subcontract_advance' => ['1630', 'Subcontract Advances'],
        'materials_on_site' => ['1600', 'Materials on Site'],
        'goods_received_not_invoiced' => ['2600', 'Goods Received Not Invoiced'],
        'accrued_subcontract_costs' => ['2610', 'Accrued Subcontract Costs'],
        'foreseeable_losses' => ['2640', 'Provision for Foreseeable Losses'],
        'job_cost_labour' => ['5620', 'Job Cost — Labour'],
        'job_cost_material' => ['5630', 'Job Cost — Material'],
        'job_cost_plant' => ['5640', 'Job Cost — Plant'],
        'job_cost_subcontract' => ['5650', 'Job Cost — Subcontract'],
        'job_cost_other' => ['5660', 'Job Cost — Other'],
        'plant_hire_recovery' => ['5670', 'Plant Internal Hire Recovery'],
        'burden_absorbed' => ['5680', 'Labour Burden Absorbed'],
        'absorption_variance' => ['5690', 'Over / Under Absorption'],
    ];

    private const GRNI = 'goods_received_not_invoiced';

    public function up(): void
    {
        if (! Schema::hasTable('accounts') || ! Schema::hasTable('settings')) {
            return;
        }

        // Already chosen — a company seeded after this change, or a second run.
        if (DB::table('settings')->where('key', 'construction.accounts')->exists()) {
            return;
        }

        $saved = json_decode((string) DB::table('settings')->where('key', 'accounting.construction_accounts')->value('value'), true);
        $saved = is_array($saved) ? $saved : [];

        if ($saved === [] && ! $this->hasConstructionAccounts()) {
            return;
        }

        $map = [];

        foreach (self::SHIPPED as $key => [$code, $name]) {
            $chosen = trim((string) ($saved[$key] ?? ''));
            $chosen = $chosen === '' || $chosen === '0' ? $code : $chosen;

            $account = DB::table('accounts')->where('code', $chosen)->first();

            // The collision: the shipped 2600 is another account in the standard chart. Never map onto it.
            if ($key === self::GRNI && $chosen === $code && ($account === null || $account->name !== $name)) {
                $account = DB::table('accounts')->where('name', $name)->first() ?? $this->createGrni($name);
            }

            if ($account !== null) {
                $map[$key] = (int) $account->id;
            }
        }

        $now = now();

        DB::table('settings')->insert([
            'key' => 'construction.accounts',
            'value' => json_encode($map),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The codes it replaces: nothing reads them any more, and leaving them would be a second answer.
        DB::table('settings')->where('key', 'accounting.construction_accounts')->delete();
    }

    public function down(): void
    {
        // The map is data a company may have changed since; the old codes are not reconstructed.
    }

    /** A company the construction accounts were seeded into: any of them at its shipped code under its own name. */
    private function hasConstructionAccounts(): bool
    {
        return DB::table('accounts')
            ->where(function ($query): void {
                foreach (self::SHIPPED as $key => [$code, $name]) {
                    if ($key !== self::GRNI) {
                        $query->orWhere(fn ($pair) => $pair->where('code', $code)->where('name', $name));
                    }
                }
            })
            ->exists();
    }

    private function createGrni(string $name): ?object
    {
        $parent = DB::table('accounts')->where('code', '2000')->value('id');

        $code = 2605;
        while (DB::table('accounts')->where('code', (string) $code)->exists()) {
            $code++;
        }

        $now = now();

        $id = DB::table('accounts')->insertGetId([
            'code' => (string) $code,
            'name' => $name,
            'type' => 'liability',
            'normal_balance' => 'credit',
            'parent_id' => $parent,
            'is_active' => true,
            'allow_manual_entry' => true,
            'description' => 'Received on site against a purchase order with no supplier invoice yet',
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('accounts')->where('id', $id)->first();
    }
};
