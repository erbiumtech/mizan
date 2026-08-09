<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Run a callback in a transaction on the connection tenant data actually lives on.
 *
 * DB::transaction() opens one on the *default* connection — the landlord database
 * in production — while everything extending TenantModel writes to `tenant`. The
 * two are separate PDO connections, so wrapping tenant writes in DB::transaction()
 * begins and commits a transaction that contains none of them, and a failure
 * halfway through leaves the earlier writes committed.
 *
 * That is not theoretical: a payslip save reversed its existing journal entry,
 * then failed validating the replacement, and the reversal stayed posted with
 * nothing left to replace it.
 *
 * In the test suite there is no tenant connection (multitenancy's
 * tenant_database_connection_name is null and TenantModel falls back to the
 * default), so this resolves to the default connection and behaves as before.
 */
class TenantTransaction
{
    public static function run(Closure $callback): mixed
    {
        return DB::connection(static::connectionName())->transaction($callback);
    }

    public static function connectionName(): ?string
    {
        return config('multitenancy.tenant_database_connection_name') ?: config('database.default');
    }
}
