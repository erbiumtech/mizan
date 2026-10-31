# Dropdowns: which are a company's to edit — audit of 2026-10-31

**Status: done for this pass.** Four dropdowns moved from a model constant to an admin-managed option
list. Every other hardcoded dropdown stays hardcoded, each for a reason given below.

## How dropdowns work here

A module declares a list in its `module.php` under `option_lists`, with the values it starts with.
`App\Support\OptionLists` reads every declaration; the `options('module.key', $record?->field)`
helper is what a form calls; `Database\Seeders\OptionListSeeder` seeds every declared list; the
Settings → Dropdown lists screen is where an administrator adds, relabels or withdraws entries.

Three properties make a list safe to use where a constant was:

- **It never comes out empty.** A list with no stored rows answers with its declared defaults, so a
  list declared in a new release works on every company the day it ships.
- **A withdrawn entry stays on the record that holds it.** The second argument to `options()` keeps
  the record's current value in the list, so editing an old row cannot blank a field nobody touched.
- **Relabelling does not reclassify.** Rows store the value; the label is only what is shown.

**Existing companies.** Provisioning runs the seeder once. A list declared later reaches an existing
company's Settings screen through the baseline top-up, which every company profile includes:

```
php artisan tenants:seed-baseline
php artisan tenants:seed-baseline --tenant=6
```

It is `firstOrCreate` on (list, value): it adds missing entries and never renames, re-enables or
removes what a company has edited. Its report now counts `option_values`, so a run says how many
entries it added. `TenantBaselineCompletenessTest` pins both halves.

## The rule a dropdown had to pass to become a list

A list is right only when **all** of these hold. When in doubt the dropdown stayed hardcoded.

1. Nothing in the application branches on a specific value: no `===`, `match`, `in_array`, scope,
   query, report, policy, posting or statutory rule reads it.
2. The column is a plain string. A database `enum` column would reject an entry a company adds.
3. It is not a status, a state machine, a behaviour setting, or a technical choice (guard, line
   ending, icon, colour, month, weekday, currency or country codes).
4. It is not part of an API, sync or import contract, where a client or a file format expects
   exactly these values.
5. Two companies would reasonably want different entries.

## Scope of the scan

Every `Select`, `SelectFilter`, `Radio` and `ToggleButtons` under `app/` with an `->options(...)`:

| | Fields |
|---|---|
| Scanned | 654 |
| Options from a query or another resource (already company data: accounts, employees, jobs, contracts…) | 236 |
| Hardcoded (literal array, model constant, or closure over one) | 418 |
| Already an option list before this pass (`options('…')`) | 32 calls over 12 lists |
| Converted in this pass | 4 |

Of the 418 hardcoded fields, the large groups were excluded by kind before any tracing:

- **Statuses and states** (`status`, `state`, `stage`, `eot_status`, `requested_status`, …): each
  is a state machine the services move through. Never a list.
- **Behaviour settings** on settings pages (`payroll_rounding`, `payroll_proration_divisor`,
  `leave_year_basis`, `leave_sandwich_rule`, `attendance_geofence_mode`, `advances_recovery_starts`,
  `work_time_source`, `percent_complete_method`, `retention_release_rule`, …): the value is an
  instruction to the code.
- **Technical choices** (`guard_name`, `line_ending`, `icon`, `colour`, `month`, `due_weekday`,
  `timezone`, `country`, `currency`, `company_date_format`, `format`, `operator`, `field`, …).
- **Scales with numeric meaning** (`rating`, `self_rating`, `manager_rating`, `final_rating`,
  `confidence`, `performance_rating_scale`).

The vocabulary-shaped remainder was traced one by one.

## Converted

| Module | File | Field | Values (unchanged) | List |
|---|---|---|---|---|
| Employees | `Employees/Filament/Resources/Employees/Schemas/EmployeeForm.php` | `marital_status` | single, married, widowed, divorced | `employees.marital_status` |
| Invoicing | `Invoicing/Filament/Resources/Contacts/RelationManagers/DocumentsRelationManager.php` (form and column) | `kind` | contract, nda, licence, registration, tax-certificate, bank-letter, other | `invoicing.contact_document_kind` |
| Lifecycle | `Lifecycle/Filament/Resources/EmploymentContracts/EmploymentContractResource.php` (form and column) | `type` | permanent, contract, probation, internship, consultant | `lifecycle.employment_contract_type` |
| Support | `Support/Filament/Resources/Tickets/TicketResource.php` | `channel` | phone, email, whatsapp, panel, in_person | `support.ticket_channel` |

Why each passes: the only readers were the form, a display column, a model default, the headcount
report (which prints the value as it is) and the ticket report dataset (a plain column). All four
columns are strings; `employees.marital_status` and `employment_contracts.type` are 20 wide, which
the list help text says and a test checks for the defaults. The model constants that held these
values (`Employee::MARITAL_STATUSES`, `ContactDocument::KINDS`, `EmploymentContract::TYPES`,
`Ticket::CHANNELS`) were read by nothing else and are removed, with a comment pointing at the list.

## Kept, with the code that depends on the value

Paths are under `app/Modules/` unless they start with `database/`.

| Module | Field | Values | Reason kept |
|---|---|---|---|
| Accounting | Beneficiary `id_type` | CNIC, NTN | Written into the bank payment file (`Accounting/Models/Payment.php:429`), whose format expects exactly these. |
| Accounting | Account `type`, journal `entry_type` | asset, liability, … / standard, … | The ledger: normal balance and posting rules read them. |
| Campaigns | `channel` | email, whatsapp, … | `Campaign.php:76,83,121` and `Consent.php:71`: the channel decides what a campaign must carry and whose consent counts. |
| Construction | Job `nature` | building, civils, … | Database `enum` column (`database/migrations/tenant/2026_08_17_120000_create_construction_jobs_table.php:50`). |
| Construction | Cost code `cost_type`, `icms_category` | labour, material, … / ICMS classes | Cost reporting rolls up by type; ICMS is a published standard. |
| ConstructionContracts | Compliance document and requirement `kind` | general_liability, lien_waiver_…, … | Database `enum` columns (`…create_construction_compliance_tables.php:45,122`). |
| ConstructionContracts | Back charge `kind` | cleanup, rework, … | Database `enum` column (`…create_construction_back_charge_tables.php:51`). |
| ConstructionContracts | Variation `origin` | employer_instruction, …, provisional_sum, dayworks | `Variation::ORIGIN_PROVISIONAL_SUM` and `ORIGIN_DAYWORKS` change how the variation is valued. |
| ConstructionContracts | Contract item `item_type`, certificate deduction `kind`, retention movement `kind` | — | Contract valuation and retention arithmetic. Not traced line by line; kept under when-in-doubt. |
| ConstructionCosting | Worker `engagement` | employee, direct, supplied | `Worker::ENGAGEMENT_EMPLOYEE` / `ENGAGEMENT_SUPPLIED` drive which fields apply and how labour is costed. |
| ConstructionCosting | Plant `ownership` | owned, hired, hired_with_operator | `PlantItem.php:114,119,124`: owned plant is charged as internal hire. |
| ConstructionCosting | Control account `kind`, `purpose`; commitment `type`; budget and cost entry `kind`; `cost_type` | — | Job cost ledger categories. Not traced line by line; kept under when-in-doubt. |
| ConstructionField | Daily log event `kind` | delay, instruction, visitor, stoppage, inspection, incident | Part of the offline sync contract (`ConstructionField/Sync/SiteDiaryWorkflow.php:63`, the offline page) and validated in `DailyLogService.php:194`. |
| ConstructionField | Daily log event `responsibility` | employer, contractor, neutral | `DailyLog.php:186`, `DailyLogEvent.php:81`: contractor-caused time is excluded from claims. |
| ConstructionField | Delay event `cause_category` | employer_risk, contractor_risk, force_majeure, … | The extension-of-time entitlement taxonomy; validated in `DelayEventService.php:55`. |
| ConstructionField | Photo `subject` | progress, concealed_work, as_built, … | `DailyLogPhoto.php:148,158`: evidential subjects are kept and reported differently. |
| ConstructionField | Daily log `working_conditions` | workable, partially_disrupted, stopped | Named constants filter the register (`DailyLogsTable.php:132`). |
| ConstructionField | Delivery `condition` | accepted, accepted_with_damage, rejected | `DailyLogDelivery.php:120,130`. |
| ConstructionField | Punch list `kind` | pre_handover, handover, defects, client, internal | `PunchList.php:106,117`: internal lists are kept out of the client's view. |
| ConstructionField | Submittal `type` | shop_drawing, product_data, … | Database `enum` column (`…create_construction_submittal_tables.php:62`). |
| ConstructionField | Programme activity `activity_type`, `source`; predecessor `relationship` | task, milestones / manual, imports / FS, SS, FF, SF | Scheduling logic and the programme import mapping (`ProgrammeActivity.php:207,346`, `ProgrammeImport.php:137,334`). |
| ConstructionQhse | Incident `kind` | near_miss, first_aid, …, fatality | `Incident.php:75,90`: recordable and first-aid sets feed the injury rates. |
| ConstructionQhse | Incident `injured_person_type` | nobody, employee, … | `Incident.php:272` compares `nobody`. |
| ConstructionQhse | NCR `source` | inspection, audit, client, … | Database `enum` column (`…create_construction_ncr_tables.php:60`). |
| ConstructionQhse | NCR `severity`, `disposition` | minor/major/critical; rework, … | `Ncr.php:215` (critical scope); disposition is a state. |
| ConstructionQhse | Permit `type` | hot_work, confined_space, … | Database `enum` column (`…create_construction_permit_tables.php:49`). |
| ConstructionQhse | Competency `kind` | training, licence, … | Database `enum` column (`…create_construction_personnel_tables.php:120`). |
| ConstructionQhse | Action `action_type` | corrective, preventive | ISO 9001's own distinction, validated in `ActionService.php:52`. Nothing branches on it, but it is a standard's term rather than a company's; **open for you to decide**. |
| ConstructionQhse | ITP `point_type`, party | hold, witness, review | Hold points stop work until released. |
| Core | Holiday `kind` | public, optional | `Holiday::closes()`: only a public holiday closes the company. |
| Core | Company `type`, custom field `type` | business, personal / text, number, … | Tenancy and the field renderer. |
| CRM | Activity `kind` | call, meeting, email, whatsapp, note | `Crm/Models/Activity.php:94` scopes calls and meetings. |
| CRM | Field visit `outcome`, refusal `reason`; sales target `kind` | — | `FieldVisitReports.php:30-31`; refusals are written by the geofence, not typed; targets compute a metric per kind. |
| Employees | Letter template `kind` | address_verification, event letters, … | `IssueEventLetter.php:61`, `VerificationCodes.php:136`. |
| Inventory | Stock location `kind`; movement `type` | warehouse, site, … / in, out, … | `StockLocation.php:98`; the stock ledger. |
| Invoicing | Contact `kind`, invoice `kind` | customer, supplier, … | `Contact::KIND_SUPPLIER` and the invoice kinds decide which side of the ledger and which document. |
| Leave, Payroll | Leave type `kind`, pay component `kind`, provident fund `kind` | — | Leave rules and payroll arithmetic. |
| Lifecycle | Employee document `kind` | cnic, passport, …, insurance | `EmployeeDocument.php:71`: insurance changes the fields and the expiry report. |
| Lifecycle | Separation `type` | resignation, termination, … | `Separation::STATUS_AFTER` sets the employee's status from it. |
| Lifecycle | Checklist template and item `kind` | onboarding, offboarding / manual, link, auto | `ChecklistService.php:68,132`. |
| Performance | Recurring task `kind` | task, daily report | `RecurringTask.php:44`: the daily report is a task of its own kind. |
| Projects | Environment `kind` | prod, qual, dev | The projects table and the project page draw one column per kind (`ProjectsTable.php:159`, `ViewProject.php:130`), and a project has one environment of each (unique index). |
| Recruitment | Interview `outcome` | pending, passed, failed, no_show | `Interview.php:67`; a state. |
| Support | Ticket `priority` | low, normal, high, urgent | An ordered scale shared with each ticket category's default priority (`Ticket.php:80`) and the ticket report's filter. |
| Work | Board `kind`, channel subscription `kind`, status `category` | kanban, scrum / … / todo, in_progress, done | Board behaviour and every Work report read the category. |

## Fields to decide

Kept by the when-in-doubt rule, each of which a company could reasonably want to edit:

- **QHSE action type** (corrective, preventive). Converting it is a one-line change once you decide
  ISO's two terms are not fixed for you.
- **Construction enum columns** (job nature, compliance kinds, back charge kinds, submittal types,
  permit types, competency kinds, NCR sources). Each would need a migration from `enum` to `string`
  first. They are standard industry vocabularies, which argues for leaving them; a company that
  needs its own permit type is the case for changing them.

Adding a list from now on needs no change to the seeder: declare it in the module's `module.php`
and call `options('module.key', $record?->field)` from the form. `OptionListTest` fails if a form
calls a list nobody declared, or if a declared list has no values.
