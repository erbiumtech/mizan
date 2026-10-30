<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * RoleSeeder runs on every deploy for every company. It used to `syncPermissions()`, so whatever a company
 * had done to its own Manager or Accountant on the Roles page was undone by the next release. It now applies
 * only what the code changed since it last ran (`roles.seeded_permissions`): new grants reach every company,
 * withdrawn ones are taken back, and the company's own additions and removals stay.
 */
class RoleSyncKeepsCustomisationsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'rolesync@test.local'));
        $this->setCurrentTenant();

        (new RoleSeeder)->run();
    }

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->with('permissions')->firstOrFail();
    }

    /** @return array<int, string> */
    private function held(string $name): array
    {
        return $this->role($name)->permissions->pluck('name')->all();
    }

    private function resync(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        (new RoleSeeder)->run();
    }

    public function test_a_fresh_companys_manager_can_work_crm_support_campaigns_and_reviews(): void
    {
        foreach (['LeadView', 'LeadConvert', 'OpportunityClose', 'PipelineUpdate', 'FieldVisitUpdate', 'SalesTargetUpdate',
            'TicketView', 'TicketUpdate', 'CampaignView', 'CampaignSend', 'ReviewView', 'ReviewUpdate', 'ReviewPrivateNotes'] as $permission) {
            $this->assertContains($permission, $this->held('Manager'), "Manager lacks {$permission}");
        }

        foreach (['LeadDelete', 'TicketDelete', 'CampaignDelete', 'ReviewDelete'] as $permission) {
            $this->assertNotContains($permission, $this->held('Manager'), "Manager holds {$permission}, which is the CEO's");
            $this->assertContains($permission, $this->held('CEO'));
        }

        // Unchanged on purpose: CRM is not every employee's business (RoleSeeder says why).
        $this->assertSame([], array_values(array_filter($this->held('Employee'), fn (string $p): bool => str_starts_with($p, 'Lead'))));
    }

    public function test_a_resync_keeps_what_the_company_added_and_what_it_removed(): void
    {
        $manager = $this->role('Manager');
        $manager->revokePermissionTo('TicketUpdate');
        $manager->givePermissionTo('JournalEntryDelete');

        $this->resync();
        $this->resync();

        $this->assertNotContains('TicketUpdate', $this->held('Manager'), 'the sync gave back a grant the company took away');
        $this->assertContains('JournalEntryDelete', $this->held('Manager'), 'the sync took away a grant the company added');
        $this->assertContains('TicketView', $this->held('Manager'));
    }

    /** A role seeded before the ledger existed is given what is new, and nothing it holds is taken away. */
    public function test_a_role_seeded_before_the_ledger_gains_the_new_grants_and_keeps_its_extras(): void
    {
        $manager = $this->role('Manager');
        $manager->forceFill(['seeded_permissions' => null])->save();
        $manager->revokePermissionTo(['TicketView', 'CampaignSend']);   // what an older release did not grant
        $manager->givePermissionTo('JournalEntryDelete');                // what the company added since

        $this->resync();

        $this->assertContains('TicketView', $this->held('Manager'));
        $this->assertContains('CampaignSend', $this->held('Manager'));
        $this->assertContains('JournalEntryDelete', $this->held('Manager'));
        $this->assertNotNull($this->role('Manager')->seeded_permissions, 'the ledger is written after the first run');
    }

    /** A grant the code withdraws is taken back — but only from a role that got it from the code. */
    public function test_a_grant_the_code_withdraws_is_revoked_once(): void
    {
        $manager = $this->role('Manager');
        $ledger = json_decode((string) $manager->seeded_permissions, true);
        // Pretend an earlier release granted JournalEntryDelete to the Manager and this one does not.
        $manager->forceFill(['seeded_permissions' => json_encode([...$ledger, 'JournalEntryDelete'])])->save();
        $manager->givePermissionTo('JournalEntryDelete');

        $accountant = $this->role('Accountant');
        $accountant->givePermissionTo('JournalEntryDelete'); // the company's own choice, not the code's

        $this->resync();

        $this->assertNotContains('JournalEntryDelete', $this->held('Manager'));
        $this->assertContains('JournalEntryDelete', $this->held('Accountant'));
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $before = ['Manager' => $this->held('Manager'), 'Administrator' => $this->held('Administrator')];

        $this->resync();

        $this->assertEqualsCanonicalizing($before['Manager'], $this->held('Manager'));
        $this->assertEqualsCanonicalizing($before['Administrator'], $this->held('Administrator'));
    }
}
