<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Support\Plans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A company may move itself up a plan, never down — docs/saas-launch-plan.md §12.3.
 *
 * The hole this closes: a trial starts on Business (§12.1), the Subscription page
 * offered every plan, and a plan change grants but never revokes. Pick Growth,
 * subscribe, and the ledger is yours for Growth money. Downgrades are support's,
 * who also reissue the agreement at the new price.
 */
class PlanDowngradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plan_covers_itself_and_the_plans_it_includes(): void
    {
        $this->assertTrue(Plans::covers('growth', 'growth'));
        $this->assertTrue(Plans::covers('starter', 'growth'));
        $this->assertTrue(Plans::covers('growth', 'business'));
        $this->assertTrue(Plans::covers(null, 'starter'), 'a company on no plan may take any');

        $this->assertFalse(Plans::covers('business', 'growth'));
        $this->assertFalse(Plans::covers('growth', 'starter'));
    }

    public function test_the_self_serve_options_are_only_the_plans_that_cover_the_current_one(): void
    {
        $offered = array_keys(Plans::upgradesFrom('growth'));

        $this->assertContains('growth', $offered);
        $this->assertContains('business', $offered);
        $this->assertNotContains('starter', $offered);
    }

    public function test_a_company_cannot_move_itself_to_a_smaller_plan(): void
    {
        $company = new Company(['name' => 'Chai Khana']);
        $company->plan = 'business';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('support');

        $company->changePlan('growth');
    }

    public function test_support_may_move_a_company_down(): void
    {
        $company = Company::factory()->create();
        $company->forceFill(['plan' => 'business', 'type' => Company::TYPE_BUSINESS])->save();

        $company->changePlan('growth', downgrade: true);

        $this->assertSame('growth', $company->fresh()->plan);
    }
}
