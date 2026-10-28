<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Support\CompanyProfiles;
use App\Support\Modules;
use App\Support\Plans;
use RuntimeException;
use Tests\TestCase;

/**
 * Plans: what a customer pays for, and what that licenses — docs/saas-launch-plan.md §3.
 *
 * Invariants over config/plans.php that hold without a database. Every one
 * guards a failure that is silent in production: a tier that sells a module
 * nobody can switch on, a module nobody can buy, a currency with no price,
 * a Growth customer missing something Starter had.
 *
 * The compatibility case matters as much as the feature: a company with no
 * plan must be licensed exactly as it was before plans existed, because every
 * company that exists today is one.
 */
class PlanTest extends TestCase
{
    public function test_the_registry_is_not_empty_and_every_entry_is_complete(): void
    {
        // Guards the guard: every assertion below iterates the registry.
        $this->assertNotEmpty(Plans::names());
        $this->assertNotEmpty(Plans::currencies());

        foreach (Plans::all() as $plan => $definition) {
            foreach (['label', 'description', 'modules', 'prices'] as $key) {
                $this->assertArrayHasKey($key, $definition, "Plan [{$plan}] has no [{$key}].");
            }

            $this->assertNotEmpty($definition['modules'], "Plan [{$plan}] sells nothing of its own.");
        }

        foreach (Plans::addOns() as $addOn => $definition) {
            foreach (['label', 'requires_plan', 'modules', 'prices'] as $key) {
                $this->assertArrayHasKey($key, $definition, "Add-on [{$addOn}] has no [{$key}].");
            }

            $this->assertTrue(
                Plans::has($definition['requires_plan']),
                "Add-on [{$addOn}] requires plan [{$definition['requires_plan']}], which does not exist.",
            );
        }
    }

    public function test_every_plan_and_add_on_names_only_real_modules(): void
    {
        // seedDefaults() matches on the key with in_array, so a typo licenses
        // nothing and says nothing about it.
        foreach (Plans::names() as $plan) {
            foreach (Plans::modules($plan) as $module) {
                $this->assertContains($module, Modules::names(), "Plan [{$plan}] sells [{$module}], which does not exist.");
            }
        }

        foreach (Plans::addOns() as $addOn => $definition) {
            foreach ($definition['modules'] as $module) {
                $this->assertContains($module, Modules::names(), "Add-on [{$addOn}] sells [{$module}], which does not exist.");
            }
        }
    }

    public function test_every_plan_is_closed_under_module_requirements(): void
    {
        // Modules::enabledFor() recurses into requirements, so a module sold
        // without them is licensed, shows a toggle, and can never be switched
        // on. It reads as a broken toggle, not as a bad tier.
        foreach (Plans::names() as $plan) {
            $this->assertClosed(Plans::modules($plan), "Plan [{$plan}]");
        }

        // An add-on is sold on top of a plan, so the closure is over both.
        foreach (Plans::addOns() as $addOn => $definition) {
            $this->assertClosed(
                array_merge(Plans::modules($definition['requires_plan']), $definition['modules']),
                "Add-on [{$addOn}] on [{$definition['requires_plan']}]",
            );
        }
    }

    public function test_every_module_is_sold_by_some_plan_or_add_on(): void
    {
        // The forcing function: a module in no plan is a module no customer can
        // ever buy, and the way you find out is a customer asking for it.
        $sold = [];

        foreach (Plans::names() as $plan) {
            foreach (Plans::modules($plan) as $module) {
                $sold[$module] = true;
            }
        }

        foreach (Plans::addOns() as $definition) {
            foreach ($definition['modules'] as $module) {
                $sold[$module] = true;
            }
        }

        $unsold = array_values(array_filter(
            Modules::names(),
            fn (string $module): bool => ! Modules::isLocked($module) && ! isset($sold[$module]),
        ));

        $this->assertSame([], $unsold, implode("\n", [
            'These modules are sold by no plan and no add-on, so no customer can ever buy them:',
            ...$unsold,
            'Add each to a plan or add-on in config/plans.php, or explain why it is unsellable.',
        ]));
    }

    public function test_each_tier_contains_the_one_below_it(): void
    {
        // starter ⊂ growth ⊂ business. `includes` makes this true by
        // construction; asserted anyway, because a Growth customer losing a
        // Starter module is the kind of regression a config edit makes quietly.
        $names = Plans::names();

        for ($i = 1; $i < count($names); $i++) {
            $lower = Plans::modules($names[$i - 1]);
            $upper = Plans::modules($names[$i]);

            $this->assertSame(
                [],
                array_values(array_diff($lower, $upper)),
                "Plan [{$names[$i]}] does not include everything [{$names[$i - 1]}] sells.",
            );

            $this->assertGreaterThan(count($lower), count($upper), "Plan [{$names[$i]}] adds nothing to [{$names[$i - 1]}].");
        }
    }

    public function test_every_plan_and_add_on_prices_every_currency(): void
    {
        foreach (array_merge(Plans::names(), array_keys(Plans::addOns())) as $plan) {
            foreach (Plans::currencies() as $currency) {
                $price = Plans::price($plan, $currency);

                $this->assertGreaterThan(0, $price, "[{$plan}] is free in [{$currency}], which nobody decided.");
            }
        }

        $this->expectException(RuntimeException::class);
        Plans::price('starter', 'XXX');
    }

    public function test_the_trial_starts_on_a_real_plan(): void
    {
        $this->assertTrue(Plans::has(Plans::trialPlan()));
        $this->assertGreaterThan(0, Plans::trialDays());
        $this->assertGreaterThan(0, Plans::minimumSeats());
    }

    public function test_an_unknown_plan_is_refused_rather_than_ignored(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no plan \[platinum\]/');

        Plans::assertExists('platinum');
    }

    public function test_no_plan_falls_back_to_the_pre_plan_behaviour(): void
    {
        // The compatibility guarantee: every company that exists today has a
        // null plan, and must be licensed exactly as CompanyProvisioningTest
        // and CompanyProfileTest already say — the profile's list, or
        // PERSONAL_DEFAULTS, or the registry defaults.
        $this->assertNull(Plans::modules(null));
        $this->assertNull(Plans::licencesFor(null, Company::TYPE_BUSINESS, null));
        $this->assertSame(Modules::PERSONAL_DEFAULTS, Plans::licencesFor(null, Company::TYPE_PERSONAL, null));
        $this->assertSame(CompanyProfiles::modules('trading'), Plans::licencesFor('trading', Company::TYPE_BUSINESS, null));
    }

    public function test_a_plan_intersects_the_profile_and_stands_alone_without_one(): void
    {
        $restaurant = CompanyProfiles::modules('restaurant');
        $starter = Plans::modules('starter');

        $licensed = Plans::licencesFor('restaurant', Company::TYPE_BUSINESS, 'starter');

        $this->assertSame(array_values(array_intersect($restaurant, $starter)), $licensed);
        $this->assertContains('attendance', $licensed);
        $this->assertNotContains('inventory', $licensed, 'Starter sold a restaurant the books.');
        $this->assertNotContains('mpr', $licensed, 'The plan licensed a module the profile does not recommend.');

        // No profile: the plan is the whole answer.
        $this->assertSame($starter, Plans::licencesFor(null, Company::TYPE_BUSINESS, 'starter'));

        // And the intersection is closed under requirements, as both inputs are.
        $this->assertClosed(Plans::licencesFor('restaurant', Company::TYPE_BUSINESS, 'business'), 'restaurant ∩ business');
    }

    /** @param  array<int, string>  $modules */
    private function assertClosed(array $modules, string $what): void
    {
        foreach ($modules as $module) {
            foreach (Modules::requirements($module) as $required) {
                if (Modules::isLocked($required)) {
                    continue;
                }

                $this->assertContains(
                    $required,
                    $modules,
                    "{$what} sells [{$module}], which requires [{$required}] — so that module can never be switched on.",
                );
            }
        }
    }
}
