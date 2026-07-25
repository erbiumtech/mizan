<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * Base model for all per-tenant domain entities. Resolves to the `tenant`
 * database connection when a company is current; in the testing environment
 * (where `multitenancy.tenant_database_connection_name` is null) it falls back
 * to the default connection, so the suite runs against a single database.
 */
abstract class TenantModel extends Model
{
    use UsesTenantConnection;
}
