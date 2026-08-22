<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Company;
use App\Support\ModuleManifest;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The five roles every company starts with, composed from what the modules grant.
 *
 * The 150-odd grants this used to hold as literal lists now live beside the permissions they name, in each
 * module's `role_grants`. `ModuleManifest` had the merge slot for them from phase 3 and nothing filled it;
 * this is the other half. See docs/module-packaging-plan.md §5 and the note on phase 3's leftovers.
 *
 * **What stays here is composition, because composition is not a module's to know.** Two of the five roles are
 * *derived* rather than declared:
 *
 *   Administrator = every permission that exists
 *   Employee      = ⋃ modules' Employee grants
 *   Accountant    = ⋃ modules' Accountant grants
 *   Manager       = Accountant ∪ ⋃ modules' Manager grants
 *   CEO           = Manager    ∪ ⋃ modules' CEO grants
 *
 * A module cannot express "Manager gets everything Accountant has", so the chain is here and each module
 * contributes only its own *additions* at each rung. That is also why a module's `Manager` list must not
 * repeat what it already gave Accountant — harmless, but it would read as a second decision.
 *
 * **Segregation of duties is the point of the middle three.** The Accountant records and cannot approve, post
 * or reverse; the Manager approves; the CEO additionally deletes. Deleting a *ledger transaction* is
 * Administrator-only even for the CEO, who corrects the books by reversing so both rows stay on the ledger.
 *
 * One thing no module can declare, recorded here because it is a decision rather than an omission: **CRM is
 * deliberately absent from the Employee role.** Leave, payslips and expense claims are things every member of
 * staff has; a sales pipeline is not — a machine operator has no leads. Granting `LeadView` to Employee would
 * put every employee's name in the owner dropdown and every prospect in front of them. A company that sells
 * creates a Sales role and grants the Lead and LeadSource groups to it. An absence cannot be declared by the
 * module that would have been granted, which is the general shape of what a composition step is for.
 */
class RoleSeeder extends Seeder
{
    /**
     * The roles that are unions of what modules declare, in the order they are built.
     *
     * `inherits` is what makes Manager and CEO additive rather than complete lists.
     *
     * @var array<string, array{inherits: string|null}>
     */
    private const COMPOSED = [
        'Employee' => ['inherits' => null],
        'Accountant' => ['inherits' => null],
        'Manager' => ['inherits' => 'Accountant'],
        'CEO' => ['inherits' => 'Manager'],
    ];

    /**
     * Seed the roles for the current company, or for every company when there is no
     * current one.
     *
     * Roles are per-company (spatie teams), and a null team is not a company. Run
     * outside a tenant — plain `db:seed --class=RoleSeeder`, which is what somebody
     * naturally types — this used to create a full set of roles belonging to no
     * company, holding every permission, reachable by nobody, while leaving each real
     * company's roles exactly as they were. It reported success, and the roles it was
     * meant to update were untouched.
     *
     * Seeding every company is what running it without naming one means. Callers that
     * do set a team — the provisioner, `tenants:artisan` — are unaffected.
     */
    public function run()
    {
        $registrar = app(PermissionRegistrar::class);
        $teamId = $registrar->getPermissionsTeamId();

        if ($teamId !== null) {
            $this->seedTeam($teamId);

            return;
        }

        foreach (Company::query()->orderBy('id')->pluck('id') as $companyId) {
            $registrar->setPermissionsTeamId($companyId);
            $this->seedTeam($companyId);
        }

        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
    }

    private function seedTeam(int $teamId): void
    {
        // Administrator holds everything, so it needs no declarations and gains a new module's
        // permissions the moment they are seeded.
        Role::firstOrCreate(['name' => 'Administrator', 'company_id' => $teamId])
            ->syncPermissions(Permission::all());

        $granted = [];

        foreach (self::COMPOSED as $role => $composition) {
            $names = $this->grantsFor($role);

            if ($composition['inherits'] !== null) {
                $names = array_merge($granted[$composition['inherits']], $names);
            }

            $names = array_values(array_unique($names));
            $granted[$role] = $names;

            Role::firstOrCreate(['name' => $role, 'company_id' => $teamId])->syncPermissions($names);
        }
    }

    /**
     * What the modules grant this role, checked before it is used.
     *
     * The check is the reason this is a method rather than an array read. A module may only grant a
     * permission **it declares itself** — otherwise a module could hand out another's permissions, which is
     * exactly the cross-module knowledge moving these lists out of here was meant to remove. And a grant
     * naming a permission that does not exist used to surface as `hasPermissionTo()` throwing at request
     * time, with the panel 500ing; here it names the module and the typo.
     *
     * @return array<int, string>
     */
    private function grantsFor(string $role): array
    {
        $manifest = ModuleManifest::all();
        $names = [];
        $problems = [];

        // Permission name => the module that declares it, via its group.
        $owner = [];
        $groupOwner = [];

        foreach ($manifest['permission_groups'] ?? [] as $module => $groups) {
            foreach ($groups as $group) {
                $groupOwner[$group] = $module;
            }
        }

        foreach ($manifest['permissions'] ?? [] as $permission) {
            if (isset($groupOwner[$permission['group']])) {
                $owner[$permission['name']] = $groupOwner[$permission['group']];
            }
        }

        foreach (ModuleManifest::manifestPaths() as $module => $path) {
            foreach ((require $path)['role_grants'][$role] ?? [] as $name) {
                if (($owner[$name] ?? null) !== $module) {
                    $problems[] = sprintf(
                        '%s grants %s to %s, but that permission belongs to %s',
                        $module,
                        $name,
                        $role,
                        $owner[$name] ?? 'no module',
                    );

                    continue;
                }

                $names[] = $name;
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode("\n", ['Role grants name permissions their module does not own:', ...$problems]));
        }

        return $names;
    }
}
