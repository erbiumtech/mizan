<?php

namespace Database\Factories;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(4));

        return [
            'name' => $name,
            'slug' => $slug,
            'database' => database_path("tenants/{$slug}.sqlite"),
            'status' => 1,
        ];
    }

    /**
     * A factory-made company is an *existing* customer: every module licensed
     * and switched on, matching what the company_modules backfill gives every
     * company that predates this feature. Only companies created through
     * CompanyProvisioner start from the config defaults (Core alone).
     *
     * Module tests set the state they need explicitly with withModules().
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Company $company) {
            $this->writeModules($company, array_fill_keys(Modules::names(), true));
        });
    }

    /**
     * @param  array<string, bool>  $modules  module => enabled (and licensed)
     */
    public function withModules(array $modules): static
    {
        return $this->afterCreating(function (Company $company) use ($modules) {
            $this->writeModules($company, $modules);
        });
    }

    /**
     * Licensed but switched off — the state a company is in after its own
     * Administrator hides a module it has paid for.
     *
     * @param  array<int, string>  $modules
     */
    public function withModulesDisabled(array $modules): static
    {
        return $this->afterCreating(function (Company $company) use ($modules) {
            foreach ($modules as $module) {
                CompanyModule::updateOrCreate(
                    ['company_id' => $company->getKey(), 'module' => $module],
                    ['licensed' => true, 'enabled' => false],
                );
            }

            modules()->flush();
        });
    }

    /**
     * @param  array<string, bool>  $modules
     */
    private function writeModules(Company $company, array $modules): void
    {
        foreach ($modules as $module => $on) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => $on, 'enabled' => $on],
            );
        }

        // The resolver caches per company per request; a test that flips state
        // after the first read would otherwise see the stale map.
        modules()->flush();
    }
}
