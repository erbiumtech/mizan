<?php

namespace App\Support;

use RuntimeException;

/**
 * Where tenant migrations live and which connection they must run on.
 *
 * Tenant migrations are not auto-loaded outside the testing environment (see
 * AppServiceProvider::boot), so every command that applies them has to pass both
 * the path and the connection. Getting either wrong is quiet and expensive:
 *
 *  - no --path  → Laravel migrates the landlord folder, reports "Nothing to
 *                 migrate", and the tenant schema silently stays behind;
 *  - no --database → the tenant schema is applied to the *landlord* database,
 *                 creating a second set of employees/projects tables there.
 *
 * Both mistakes are easy to make by hand, so the values live here and
 * `tenants:migrate` uses them.
 */
class TenantMigrations
{
    public const PATH = 'database/migrations/tenant';

    /**
     * The dedicated tenant connection.
     *
     * @throws RuntimeException when tenancy is configured to share the default
     *                          connection, which would send tenant migrations
     *                          to the landlord database.
     */
    public static function connection(): string
    {
        $connection = config('multitenancy.tenant_database_connection_name');

        if (! $connection || $connection === config('database.default')) {
            throw new RuntimeException(
                'No dedicated tenant database connection is configured '
                .'(multitenancy.tenant_database_connection_name is empty or equals the default connection). '
                .'Running tenant migrations now would apply them to the landlord database.'
            );
        }

        return $connection;
    }

    /**
     * Artisan parameters for a tenant migration command.
     *
     * @return array<string, mixed>
     */
    public static function parameters(bool $pretend = false): array
    {
        $parameters = [
            '--database' => static::connection(),
            '--path' => static::PATH,
            '--force' => true,
        ];

        if ($pretend) {
            $parameters['--pretend'] = true;
        }

        return $parameters;
    }
}
