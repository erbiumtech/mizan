<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class FilamentResourcesSmokeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /**
     * Render every registered Filament resource's List page and assert it loads.
     * Grants a super-admin gate so policies don't block rendering; empty tables
     * are fine — this catches config/column/action wiring errors.
     */
    public function test_all_resource_list_pages_render(): void
    {
        Gate::before(fn () => true);

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->setCurrentTenant();

        $resources = Filament::getPanel('admin')->getResources();
        $this->assertNotEmpty($resources, 'No Filament resources registered');

        $failures = [];
        foreach ($resources as $resource) {
            // Skip resources gated to roles the test user doesn't hold (e.g. admin-only).
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                continue;
            }

            $pages = $resource::getPages();
            foreach (['index', 'create'] as $key) {
                if (! isset($pages[$key])) {
                    continue;
                }
                $page = $pages[$key]->getPage();
                try {
                    Livewire::test($page)->assertSuccessful();
                } catch (\Throwable $e) {
                    $failures[] = class_basename($resource)." [{$key}] → ".$e->getMessage();
                }
            }
        }

        if ($failures) {
            $this->fail("Resource render failures:\n - ".implode("\n - ", $failures));
        }

        $this->addToAssertionCount(1);
    }
}
