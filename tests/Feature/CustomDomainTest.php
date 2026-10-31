<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Support\CustomDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Custom domains — docs/saas-launch-plan.md §8.8.
 *
 * What a custom domain changes is the host, the branding and the front door,
 * never the path. The claims: a domain is accepted only on a plan that sells
 * it and only once its DNS points here; the trusted-host list follows the
 * column; on a company's host `/` and `/admin` go to its panel, another
 * company's slug is a 404, and the login page wears its name; the ask
 * endpoint answers 200 for a claimed host and 404 for anything else.
 */
class CustomDomainTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://app.mizan.test']);
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);

        $this->acme = Company::factory()->create(['name' => 'Acme Textiles', 'slug' => 'acme']);
        $this->acme->forceFill(['plan' => 'business'])->save();
        $this->other = Company::factory()->create(['name' => 'Other Co', 'slug' => 'other']);

        CustomDomains::fakeDns([
            'app.mizan.test' => ['203.0.113.10'],
            'hr.acme.pk' => ['app.mizan.test'],
            'portal.acme.pk' => ['203.0.113.10'],
            'elsewhere.pk' => ['198.51.100.7'],
        ]);
        CustomDomains::flush();
    }

    protected function tearDown(): void
    {
        CustomDomains::fakeDns(null);
        CustomDomains::flush();

        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────── the host ──

    public function test_a_domain_is_normalised_and_the_platforms_own_host_is_never_one(): void
    {
        $this->assertSame('hr.acme.pk', CustomDomains::normalise(' HR.Acme.PK '));
        $this->assertSame('hr.acme.pk', CustomDomains::normalise('https://hr.acme.pk/admin/acme'));
        $this->assertSame('hr.acme.pk', CustomDomains::normalise('hr.acme.pk.'));
        $this->assertNull(CustomDomains::normalise('app.mizan.test'));
        $this->assertNull(CustomDomains::normalise('acme.app.mizan.test'));
        $this->assertNull(CustomDomains::normalise('203.0.113.10'));
        $this->assertNull(CustomDomains::normalise('not a host'));
        $this->assertNull(CustomDomains::normalise('localhost'));
        $this->assertNull(CustomDomains::normalise(''));
    }

    public function test_the_plan_must_sell_it(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('plan');
        CustomDomains::claim($this->other, 'hr.other.pk');
    }

    public function test_a_domain_that_does_not_point_here_is_refused(): void
    {
        try {
            CustomDomains::claim($this->acme, 'elsewhere.pk');
            $this->fail('expected the claim to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('CNAME record elsewhere.pk → app.mizan.test', $e->getMessage());
        }

        $this->assertNull($this->acme->fresh()->domain);
        $this->assertSame(['app.mizan.test'], CustomDomains::trustedHosts());
    }

    public function test_a_cname_to_the_platform_or_a_matching_address_is_accepted_and_trusted(): void
    {
        CustomDomains::claim($this->acme, 'HR.acme.pk');
        $this->assertSame('hr.acme.pk', $this->acme->fresh()->domain);
        $this->assertSame(['app.mizan.test', 'hr.acme.pk'], CustomDomains::trustedHosts());
        $this->assertTrue($this->acme->is(CustomDomains::companyForHost('hr.acme.pk')));

        // An A record at the platform's address is as good as a CNAME.
        CustomDomains::claim($this->acme, 'portal.acme.pk');
        $this->assertSame('portal.acme.pk', $this->acme->fresh()->domain);
        $this->assertSame(['app.mizan.test', 'portal.acme.pk'], CustomDomains::trustedHosts(), 'the old host is no longer trusted');
        $this->assertNull(CustomDomains::companyForHost('hr.acme.pk'));

        // Cleared: back on the platform address.
        CustomDomains::claim($this->acme, '');
        $this->assertNull($this->acme->fresh()->domain);
        $this->assertSame(['app.mizan.test'], CustomDomains::trustedHosts());
    }

    public function test_a_domain_belongs_to_one_company(): void
    {
        CustomDomains::claim($this->acme, 'hr.acme.pk');
        $this->other->forceFill(['plan' => 'business'])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already in use');
        CustomDomains::claim($this->other, 'hr.acme.pk');
    }

    // ──────────────────────────────────────────────────────── the front door ──

    public function test_on_its_host_a_company_is_the_only_one_reachable(): void
    {
        CustomDomains::claim($this->acme, 'hr.acme.pk');

        $this->get('http://hr.acme.pk/')->assertRedirect('http://hr.acme.pk/admin/acme');
        $this->get('http://hr.acme.pk/admin')->assertRedirect('http://hr.acme.pk/admin/acme');
        $this->get('http://hr.acme.pk/admin/other')->assertNotFound();
        $this->get('http://hr.acme.pk/admin/other/employees')->assertNotFound();

        // Its own panel, and the routes that have no company yet, are reachable.
        $this->get('http://hr.acme.pk/admin/acme')->assertRedirect();
        $this->assertStringContainsString('/admin/login', $this->get('http://hr.acme.pk/admin/acme')->headers->get('Location'));
        $this->get('http://hr.acme.pk/admin/login')->assertOk()->assertSee('Acme Textiles');

        // The platform host is untouched: its root is the public site's home page, not a tenant's.
        $this->get('http://app.mizan.test/')->assertOk()->assertDontSee('Acme Textiles');
        $this->get('http://app.mizan.test/admin/login')->assertOk()->assertDontSee('Acme Textiles');
    }

    public function test_the_ask_endpoint_answers_only_for_a_claimed_host(): void
    {
        CustomDomains::claim($this->acme, 'hr.acme.pk');

        $this->get('/domains/check?domain=hr.acme.pk')->assertOk()->assertSee('OK');
        $this->get('/domains/check?domain=HR.ACME.PK')->assertOk();
        $this->get('/domains/check?domain=elsewhere.pk')->assertNotFound();
        $this->get('/domains/check?domain=app.mizan.test')->assertNotFound();
        $this->get('/domains/check')->assertNotFound();
    }
}
