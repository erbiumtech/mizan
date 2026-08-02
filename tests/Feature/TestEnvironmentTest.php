<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The suite must not depend on whose machine it runs on.
 *
 * phpunit.xml pinned the landlord connection to sqlite but said nothing about the
 * tenant one, so it fell through to the developer's .env. On a machine running
 * tenants on MySQL, the 24 tests that provision a real tenant database handed
 * MySQL an sqlite file path as a schema name and failed with
 * "Identifier name '/Users/.../company-a-6a6f03.sqlite' is too long" — which says
 * nothing about the actual problem, and looks like a broken test rather than a
 * broken environment.
 *
 * These assertions fail immediately and say what is wrong instead.
 */
class TestEnvironmentTest extends TestCase
{
    public function test_the_landlord_connection_is_the_test_one(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_the_tenant_connection_is_the_test_one(): void
    {
        $this->assertSame(
            'sqlite',
            config('database.connections.tenant.driver'),
            'The tenant connection is not sqlite, so it is coming from your .env rather than phpunit.xml. '
            .'Tests that provision a tenant write real sqlite files and will fail against any other driver.'
        );
    }

    /**
     * Not decoration: two of these were found by tests that passed for the wrong
     * reason — impersonation on the array session driver, which cannot survive
     * between requests, and tenancy scoping on a panel that was never booted.
     */
    public function test_the_settings_earlier_bugs_hid_behind_are_pinned(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('mail.default'));
    }
}
