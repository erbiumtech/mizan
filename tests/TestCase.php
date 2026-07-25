<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Default permission "team" (company) id for tests. spatie/laravel-permission
     * runs with teams enabled, so every role seed/assignment/check needs a team
     * context. Domain tests operate under this single default company; tenant
     * tests override it via InteractsWithTenant / makeCurrent.
     */
    protected int $defaultTeamId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->defaultTeamId);
    }
}
