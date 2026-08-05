# A platform panel for super admins: plan

One thing, decided: **installation-level work stops happening inside a customer's
URL.** A super admin gets a second Filament panel at `/platform` with no tenant, where
they create companies, grant licences and appoint administrators — and a link into
`/admin/{company}` for anything that belongs to a company.

The authority already exists. `is_super_admin` means `Gate::before` returns true for
every ability, `getTenants()` returns every company, `canAccessTenant()` passes for any
of them, `canCreateCompanies()` is theirs alone, and `CompanyResource` plus
`CompanyPolicy` are already gated to them. Your code already calls them platform admins
(`User::scopeExceptPlatformAdmins`). What is missing is not permission. It is **a place
to stand that is not a company.**

## 1. What is actually wrong today

`AdminPanelProvider` declares `->tenant(Company::class, slugAttribute: 'slug')`, so every
route in the application is `/admin/{company}/…`. There is no URL from which to manage
the installation. To create a company you first pick an unrelated company, and while you
do it:

- that company's **database is the connected one** (`SwitchTenantDatabaseTask`);
- that company's **licences decide what you can see** (`BelongsToModule`);
- spatie's **permission team is that company**, so roles resolve in its team;
- the Companies list, the Users list and the Roles list all render *as if* they were part
  of that company's administration.

None of that is wrong for a company administrator. All of it is wrong for the person
administering the installation, and the confusion is not cosmetic: it is why creating a
company looked like it duplicated roles (`682aea7`), and why a role seeded with a null
team belonged to no company at all (`96bdbc3`).

## 2. What it does and does not buy

It buys a correct context: no tenant database attached, no licence state, no company's
roles in scope, and a front door that only a platform admin can open.

It does **not** buy any new capability. Every action below is possible today; this moves
where it happens. If that sounds like a small return, note that the two bugs above were
both caused by the missing distinction, and a third — a super admin editing the wrong
company's settings because the URL said one company while the form said another — is
available today and has simply not been hit yet.

It also does not remove the company panel's own administration. A company Administrator
keeps Users, Roles and Company Settings exactly as now. The change is subtractive for
them only in that Companies leaves their sidebar, which is already invisible to them.

## 3. The constraint that shapes everything

**No tenant means no tenant connection.** `config('database.connections.tenant')` is
pointed at a company's database by the switch-tenant task when a tenant becomes current.
On a tenant-less panel that never happens, so any query against a model extending
`App\Models\TenantModel` either fails or — worse — reaches whatever connection was left
configured.

So the platform panel may only carry **landlord-backed** resources:

| Landlord (allowed) | Tenant (not allowed) |
|---|---|
| `users`, `companies`, `company_modules` | `fiscal_years`, `settings`, `email_templates` |
| `permissions`, `roles`, `model_has_roles` | `custom_fields`, `custom_field_values`, `comments` |
| `activity_log`, `table_views` | everything in payroll, accounting, invoicing, billing, inventory, projects |

Note the trap in the second column: `FiscalYear`, `EmailTemplate`, `CustomField` and
`Comment` are *tenant* models whose resources live in the **Core** module. Core being
"always available" does not make them reachable without a company. §8 covers the test
that keeps this honest.

## 4. What moves, and where it lands

```
/platform                          (new panel, no tenant)
├── Companies                      moved from the admin panel
│   └── per company:
│       ├── Members                relation manager — attach a user, appoint an Administrator
│       ├── Roles                  relation manager — this company's five roles
│       └── Licences               relation manager — company_modules for this company
├── Users                          new, unscoped: every account in the installation
├── Permissions                    moved — landlord rows, global, read-mostly
└── Activity log                   moved — landlord rows, filterable by company

/admin/{company}                   (unchanged panel)
├── Users                          stays: the current company's own members
├── Roles                          stays: this company's roles
├── Company Settings, Fiscal Years, Email Templates, Custom Fields, Comments
└── every module: payroll, accounting, invoicing, billing, inventory, projects
```

Three deliberate choices in that layout.

**Roles and licences as relation managers under a company, never flat lists.** A flat
list of roles across companies is five names times N companies with nothing to tell them
apart — which is exactly the "duplicate roles" report, and displaying each set under its
company makes the ambiguity impossible rather than merely fixed. The same argument
applies to licences: the `Modules` page answers "what does *this* company have", and the
platform question is "what does each company have".

**`CompanyResource` moves rather than being copied.** One place companies are managed. A
copy would leave two forms to keep in step and two answers to "where do I create a
company".

**Users is a second resource over the same model, not a widened one.** `UserResource`
scopes to the current company twice over — `$isScopedToTenant` with
`$tenantOwnershipRelationshipName = 'companies'`, *and* `getEloquentQuery()` calling
`->inCurrentCompany()->exceptPlatformAdmins()` so the boundary also holds off a panel
request. Both of those are correct for a company panel and neither can be conditionally
switched off without making that page's boundary depend on which panel is asking. A
separate `PlatformUserResource` with no scoping is the honest version.

## 5. The four things that will bite

1. **spatie's team id is null on this panel.** `SyncSpatieTenant` runs on Filament's
   `TenantSet` event, which never fires without tenancy. So `hasRole()` and
   `hasPermissionTo()` consult team `null` and find nothing. **Every `canAccess()` on a
   platform class must gate on `isSuperAdmin()`** — never on a role or a permission. The
   existing `Modules` page gates on `hasRole('Administrator')` and would be invisible to a
   super admin standing in no company; that is the shape of the mistake to avoid.

2. **`canAccessPanel()` currently only checks `status === 1`.** As written, every active
   user could open `/platform`, and `Gate::before` would then grant them everything. This
   is the one security-critical line in the whole plan:

   ```php
   public function canAccessPanel(Panel $panel): bool
   {
       return (int) $this->status === 1
           && ($panel->getId() !== 'platform' || $this->isSuperAdmin());
   }
   ```

   With a test that a company Administrator gets 403 on every platform route, not just on
   the index.

3. **Module gating does not apply.** `BelongsToModule::moduleIsAvailable()` reads the
   current company's licences; with no company there are none. Platform classes must not
   use the trait — which is why they get their own namespace and their own plugin (§6)
   rather than being discovered by the module plugins.

4. **`Company::current()` must never be called on this panel.** `ActivityLog` stamps
   `company_id` from `Company::current()` on create, and its scope filters on it. On the
   platform panel that is null, which is correct for a landlord-level view but means the
   activity list must filter by an explicitly chosen company rather than by the current
   one. `ResolveCompanyFromRoute` is for the pages outside the panel and is unaffected.

## 6. Layout and registration

```
app/Modules/Core/Filament/Platform/
├── Resources/
│   ├── Companies/                  moved from ../Resources/Companies
│   │   └── RelationManagers/       MembersRelationManager, RolesRelationManager,
│   │                               LicencesRelationManager
│   ├── Users/                      new PlatformUserResource
│   ├── Permissions/                moved
│   └── ActivityLogs/               moved
└── Pages/
    └── Dashboard.php               companies, users, licences at a glance
```

Under **Core**, because Core is the locked module that owns users, roles, companies and
the audit trail — and because a "platform" module would be a licence nobody grants,
which is a category error in a system where a module is something a company buys.

Registered by a second plugin, `CorePlatformPlugin`, which discovers only that directory
and is listed **only** by `PlatformPanelProvider`. `CorePlugin` keeps discovering
`Filament/Resources` and `Filament/Pages` for the admin panel; add an `except:` for the
`Platform` subdirectory so nothing is registered on both panels.

`PlatformPanelProvider` is `AdminPanelProvider` minus tenancy: same brand colours, same
`->login()`, same command palette and impersonation render hooks, no `->tenant()`, no
`->tenantMenu()`, no `->tenantRegistration()`, and `->plugins([new CorePlatformPlugin])`
instead of `Modules::plugins()`.

## 7. Phases

Each stops cleanly; nothing later is required for what came before to be useful.

1. **Panel and front door — half a day.** `PlatformPanelProvider`, the panel-aware
   `canAccessPanel()`, `CorePlatformPlugin`, and Companies moved across. *Done when: a
   super admin creates a company at `/platform/companies/create` and a company
   Administrator gets 403 at `/platform`.*
2. **Company relation managers — 1 day.** Members (attach a user, appoint an
   Administrator — this is `CompaniesTable::assignAdmin` reshaped), Roles, Licences.
   *Done when: appointing an administrator, and granting a module, are both done from the
   company's own page, and `Modules` on the admin panel still edits only its own
   company.*
3. **Users, Permissions, Activity log — half a day.** `PlatformUserResource` unscoped;
   Permissions and the activity log moved with a company filter. *Done when: the platform
   Users list shows accounts from every company and the admin panel's list still shows
   only its own.*
4. **Guard rails — half a day.** §8. *Done when: the suite fails if somebody adds a
   tenant-backed resource to the platform panel.*

Two to four days in total. Phase 1 is worth doing on its own even if the rest waits: it
is the phase that removes the wrong context.

## 8. Tests, including the ones that need to learn there are two panels

New:

- **`PlatformPanelAccessTest`** — a super admin reaches every platform route; an active
  company Administrator gets 403 on every one of them; an inactive super admin gets
  nothing. This is the security boundary, so it enumerates routes rather than sampling.
- **`PlatformPanelIsLandlordOnlyTest`** — every resource registered on the platform panel
  has a model whose connection is *not* the tenant one. This is the §3 constraint as a
  test, and it is what stops someone adding a payslip list and getting a baffling
  connection error instead of a clear failure.
- **`PlatformCompanyAdministrationTest`** — appointing an administrator through the
  Members relation manager gives that user the Administrator role *in that company's
  team* and in no other; granting a licence writes one `company_modules` row.

Existing, needing to become panel-aware rather than assuming one panel:

| Test | What it assumes today |
|---|---|
| `NavigationGroupsTest` | the exact set of sidebar groups — the platform panel has its own, shorter set |
| `ModuleCoverageTest` | every Filament class belongs to exactly one module — platform classes map to `core`, and need `ModuleMap` entries |
| `CrudRedirectsToListingTest` | iterates the admin panel's create/edit pages |
| `FilamentResourcesSmokeTest` | iterates the admin panel's resources |
| `PanelAccessTest` | one panel's access rules |
| `CompanyManagementTest`, `CompanyMembershipTest` | company administration reached through `/admin/{company}` |

`ModuleMap::RESOURCES['core']` gains the moved classes under their new FQCNs. The morph
map is untouched — no model moves, and the aliases are the legacy `App\Models\…` strings
precisely so class moves cost nothing.

## 9. Decisions still open

1. **Path.** `/platform` throughout this document, matching the vocabulary already in
   `scopeExceptPlatformAdmins`. `/super` and `/console` are the alternatives.
2. **Login.** Its own `/platform/login`, or share `/admin/login`? The session is the same
   guard either way, so a super admin signed into one is signed into the other; the only
   question is whether the entrance is separate. Recommended: its own, so the two
   audiences never see each other's front door.
3. **Impersonation.** Its natural home is the platform panel — "act as this user" is a
   cross-company act, and `Impersonation` already treats a super admin specially. Moving
   it there would let the company panel drop its cross-company affordances. Not in the
   phases above; decide before phase 3, since it changes what the platform Users list
   needs to offer.
4. **`->tenantRegistration(RegisterCompany::class)`.** Once companies are created on the
   platform panel this is a second route to the same act, gated by the same check.
   Recommended: drop it in phase 1 and delete `RegisterCompany`.

## 10. What not to do

- **Do not make tenancy conditional on the existing panel.** Filament builds routes with
  the tenant prefix at boot; "sometimes tenanted" means fighting every URL generator in
  the framework, and `route:cache` would bake in whichever shape existed at cache time.
- **Do not put a company-less landing page on the admin panel instead.** It is half a day
  and it does not address §1: installation work would still run with a customer's database
  attached and their licences deciding the sidebar.
- **Do not let the platform panel reach tenant data by quietly calling
  `makeCurrent()`.** If a platform screen genuinely needs to show something from a
  company's database, the honest form is a link into `/admin/{company}`, where the whole
  request is that company's. Switching tenants mid-request to render one table is how a
  page ends up reading one company and writing another.
