<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A query builder on the connection tenant data actually lives on.
 *
 * `DB::table()` builds against the **default** connection — the landlord database in production — while
 * everything extending `TenantModel` reads and writes `tenant`. They are separate databases, so a hand-written
 * query against a tenant table through `DB::table()` looks for that table in the landlord and does not find it.
 *
 * `TenantTransaction` records the same lesson for `DB::transaction()`, down to the detail that it "begins and
 * commits a transaction that contains none of them". This is the query half of it, and it was found the same
 * way: `AccrualService::accrueGoodsReceivedNotInvoiced()` threw
 * *"Base table or view not found: 1146 Table 'mpr.construction_goods_receipt_lines' doesn't exist"* the first
 * time a cost period was opened outside the test suite.
 *
 * **The test suite cannot catch this.** `multitenancy.tenant_database_connection_name` is null under test,
 * `TenantModel` falls back to the default, and the two connections coincide — so every bare `DB::table()` on a
 * tenant table hits the right rows by accident and every test passes. `TenantConnectionGuardTest` is what
 * stands in for the coverage the suite structurally cannot give.
 */
class TenantDb
{
    /** @param  string  $table  A tenant table, optionally with an alias: `construction_cost_entries as ce`. */
    public static function table(string $table): Builder
    {
        return static::connection()->table($table);
    }

    public static function connection(): ConnectionInterface
    {
        return DB::connection(TenantTransaction::connectionName());
    }
}
