# Replicate records

**Replicate** makes a new record from an existing one. It appears as a row action in a resource's
list, as a header action on its edit and view pages, and in the ⋮ menu on the invoice view. It is
offered only for models that declare themselves replicable, and only to someone the model's policy
lets `create` one, which also means the module has to be available.

## How it works

| Piece | Where | What it does |
|---|---|---|
| `Replicable` | `app/Support/Replication/Replicable.php` | The interface a model implements to be copyable. |
| `ReplicatesRecords` | `app/Support/Replication/ReplicatesRecords.php` | Defaults: copy every attribute, no children, nothing unique, save normally. Includes `suggestCopyOf()`. |
| `RecordReplicator` | `app/Support/Replication/RecordReplicator.php` | Does the copy, as described below. |
| `ReplicateRecordAction` | `app/Filament/Actions/ReplicateRecordAction.php` | The button. Extends Filament's `ReplicateAction`. |

A copy goes through these steps in order:

1. **Refuse** if the record says `canBeReplicated()` is false. For example, a credit note.
2. **Eager-load** the declared child relations, so copying a long invoice does not run one query per line.
3. **Open one transaction** on the record's own connection, which is the current company's database.
4. Inside that transaction:
   1. Run `replicate()` without the key, the timestamps, the soft-delete column and anything in `replicationExcept()`.
   2. Apply `replicationResets()`. A value can be fixed, or a closure that receives the original and the copy.
   3. Set the unique fields to what the person entered. Each is checked again for uniqueness, including soft-deleted rows, within its `replicationUniqueScopes()` columns.
   4. Save through `persistReplica()`. Usually that is a plain save. A work item instead goes through `WorkItems::create()`, which takes the project's lock to allocate the next key and number.
   5. Copy children. Has-many and morph-many rows are duplicated, leaving out any attributes listed against the relation. Belongs-to-many relations are linked to the same rows.
   6. Copy custom field values row for row, so an encrypted value is never decrypted.
   7. Call `afterReplication()`.
5. **Log after commit.** The entry reads `Replicated from <label>`, with event `replicated`, `replicated_from` set to the original's type and id, and the causer. It is written only after the commit because the activity log lives in the landlord database and cannot roll back with the tenant transaction. A copy that failed therefore leaves no entry.

The modal asks only for the unique fields, prefilled with a free suggestion such as `5000-COPY` or
`5000-COPY2`. If a model has no unique fields, the modal is just a confirmation. After a copy the
action redirects to the copy's view page, or to its edit page when the resource has no view page.

### Making another model replicable

```php
class Thing extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    // Only what differs from the defaults:
    public function replicationUniqueFields(): array
    {
        return ['code' => self::suggestCopyOf('code')];
    }

    public function replicationResets(): array
    {
        return ['status' => self::STATUS_DRAFT, 'approved_at' => null];
    }

    public function replicationRelations(): array
    {
        return ['lines'];
    }
}
```

Then add `ReplicateRecordAction::make()` to the resource's `recordActions()` and to its edit or view
`getHeaderActions()`. `tests/Unit/ReplicableCoverageTest.php` fails on three mistakes:

- a replicable model whose resource has no button
- a button on a resource whose model is not replicable
- a declared relation that does not exist

It also fails if any record on the exclusion list below becomes replicable.

**Only reset columns that exist.** A reset on a column the table does not have makes the insert fail
when someone presses the button. Check the migrations, not just `$fillable`.

## What a copy of the main documents looks like

**Invoice or bill.** The copy is a new draft:

- **Kept:** customer or supplier, project, currency, rate, memo, totals and every line.
- **Number:** `invoice_number` is cleared, so the creating hook draws the next `INV-` or `BILL-` number.
- **Dates:** `invoice_date` is today. `due_date` is today plus the same number of days the original allowed.
- **Fiscal year:** the year containing today.
- **Payment and posting:** `amount_paid` is 0, and there is no `journal_entry_id`.
- **Recurring and credit links:** no recurring parent or period, and no credit fields.
- **FBR:** `fbr_status` is `not_required`, with no IRN, USIN or QR.
- **Attachment:** not copied, because the file belongs to the original.
- **Lines:** each line is copied without its construction cost allocation (`cost_entry_id`, `commitment_line_id`).
- **History:** payments, events, FBR submissions and stock movements belong to the original and are not copied.

Credit and debit notes refuse to be copied, because each exists only against the document it corrects.

**Quotation.** The copy is a new draft at version 1:

- **Number:** a new one.
- **Dates:** issued today, valid for the same number of days as the original.
- **Cleared:** no supersession, acceptance, decline or linked invoice.
- **Ownership:** created by whoever copied it.
- **Lines:** copied.

**Work item.** The copy stays in the same project:

- **Allocated by `WorkItems::create()`:** the next key and number, the first `todo` status of the project's workflow, and the last rank.
- **Kept:** title, type, priority, description, assignee (with `assigned_at` set to now), parent, release, estimates and due date.
- **Cleared:** not in a sprint, with no carry-over and no started, resolved or archived dates. No resolution, AI summary or import marker.
- **Reporter:** the person who copied it.
- **Labels:** linked to the same labels.
- **Not copied:** comments, attachments, links, commits and pull requests.

**Account.** The copy needs a new code, which is asked for and checked for uniqueness. It starts with
a `balance` of 0. Ledger lines and child accounts are not copied.

**Project.** The copy needs a new code, which is asked for. It starts as `planned`, with no work key,
so one is derived from the new code when the first item is created. Item numbers start from 1. Work
items, members, time and environments are not copied.

## Audit of every resource

There are 157 resources. 58 offer Replicate and 99 do not. Each "No" gives the reason it must not be
copied.

| Module | Resource | Replicable | Why | Unique fields asked | Relations copied | State reset |
|---|---|---|---|---|---|---|
| Accounting | AccountResource | **Yes** | Chart-of-accounts master data; a sibling account is usually a copy with a new code. | `code` | — | `balance` |
| Accounting | BankFileFormatResource | **Yes** | A bank file template; the copy is unlinked from the bank account (one format per account). | — | — | `company_bank_account_id`, `previewed_at` |
| Accounting | BankStatementLineResource | No | Imported bank data; a copy would be a phantom transaction. | | | |
| Accounting | BankStatementResource | No | Imported bank data. | | | |
| Accounting | BankResource | No | Shared bank directory (IBAN/branch codes); one row per real bank. | | | |
| Accounting | BeneficiaryResource | **Yes** | A payee template: same bank, payment and withholding settings, never the same person. | — | — | `account_no`, `iban`, `id_number`, `email`, `phone`, `address_line_1`, `address_line_2`, `is_petty_cash_custodian`, `engaged_on`, `engagement_ended_on` |
| Accounting | BudgetResource | **Yes** | A plan; next year's budget starts from this one. | `name` | `lines` | — |
| Accounting | ChequeBookResource | No | A physical cheque series; numbers are real. | | | |
| Accounting | ChequeLayoutResource | **Yes** | A print template; the copy is unlinked from the bank account (one layout per account). | — | — | `company_bank_account_id` |
| Accounting | ChequeResource | No | A physical, numbered document. | | | |
| Accounting | CompanyBankAccountResource | No | A real bank account with a ledger account behind it. | | | |
| Accounting | CurrencyResource | No | ISO currencies; there is exactly one of each. | | | |
| Accounting | FixedAssetResource | **Yes** | Another asset from the same batch; it gets its own `FA-` tag and starts undepreciated. | — | — | `asset_code` (drawn afresh), `accumulated_depreciation`, `status`, `disposed_at`, `journal_entry_id` |
| Accounting | JournalEntryResource | No | Posted ledger record. | | | |
| Accounting | JournalEntryLineResource | No | Posted ledger record. | | | |
| Accounting | LoanResource | No | A real borrowing with a repayment schedule. | | | |
| Accounting | PaymentResource | No | Money moved; a copy would pay twice. | | | |
| Accounting | ScheduledTransactionResource | **Yes** | A template for recurring journals; copied inactive so it never posts twice. | — | `lines` | `is_active` |
| Accounting | TransactionTypeResource | **Yes** | Master data. | `name`, `code` | — | — |
| Advances | AdvanceTypeResource | **Yes** | Master data. | `name` | — | — |
| Advances | AdvanceResource | No | Money paid to an employee with a recovery schedule. | | | |
| Attendance | AttendanceDayResource | No | A fact about a day; computed from punches. | | | |
| Attendance | AttendanceRegularizationResource | No | A request about a specific day. | | | |
| Attendance | EmployeeWorkPatternResource | No | An assignment of a pattern to a person for a period. | | | |
| Attendance | OvertimeRequestResource | No | A request about specific hours. | | | |
| Attendance | RestDaySwapResource | No | A request about specific days. | | | |
| Attendance | WorkPatternResource | **Yes** | A schedule template with its days. | — | `days` | `is_default` |
| Billing | BillingRunResource | No | A billing run that issued documents. | | | |
| Campaigns | CampaignResource | **Yes** | Reusing a campaign is the common case; the copy is a draft and has sent nothing. | — | — | `status`, `scheduled_at`, `sent_at`, `created_by` |
| Campaigns | SegmentResource | **Yes** | An audience definition. | — | — | — |
| Construction | CostCodeResource | **Yes** | Master data; the copy is a leaf under the same parent. | `code` | — | `path`, `is_leaf` |
| Construction | JobResource | **Yes** | A new job set up like this one (client, standard, retention, advance, LDs, terms); budgets, contracts and costs stay with the original. | `code` | — | `path`, `status` (tender), `project_id`, `stock_location_id`, `revised_completion_date`, `actual_completion_date`, `substantial_completion_date`, `final_certificate_date`, `closed_at` |
| ConstructionContracts | BackChargeResource | No | A claim against a specific party for a specific event. | | | |
| ConstructionContracts | ComplianceDocumentResource | No | A real certificate or policy on file. | | | |
| ConstructionContracts | ComplianceRequirementResource | No | Unique per contract and kind, so a copy could never be saved. | | | |
| ConstructionContracts | ContractResource | No | A signed contract. | | | |
| ConstructionContracts | PaymentCertificateResource | No | A certified, legally issued document. | | | |
| ConstructionContracts | ProgressClaimResource | No | A claim for a specific period. | | | |
| ConstructionContracts | RetentionMovementResource | No | Posted money movement. | | | |
| ConstructionContracts | VariationResource | No | An instructed change to a specific contract. | | | |
| ConstructionCosting | CommitmentResource | **Yes** | A repeat order as a new draft; it commits nothing until approved and issued. | — | `lines` (less `requisition_line_id` and the variance acceptance) | `number` (the year's next for its type), `status`, `contract_id`, `order_date` (today), `required_by`, `supplier_reference`, `approved_*`, `issued_*`, `closed_*`, `close_reason` |
| ConstructionCosting | ControlAccountResource | No | Unique per purpose and per account and kind: a copy could never be saved. | | | |
| ConstructionCosting | CostEntryResource | No | Posted cost. | | | |
| ConstructionCosting | CostPeriodResource | No | A period that opens and closes once. | | | |
| ConstructionCosting | GoodsReceiptResource | No | A record of goods that arrived. | | | |
| ConstructionCosting | JobBudgetResource | **Yes** | The next draft version of the same job's budget with its lines, as BudgetService opens a revision. | — | `lines` | `version_no` (the job's next), `kind` (an original becomes a revision), `status`, `is_baseline`, `is_current`, `effective_from`, `approved_at`, `approved_by` |
| ConstructionCosting | LabourRateResource | **Yes** | A rate card row. | — | — | `created_by` |
| ConstructionCosting | LabourRecordResource | No | Hours actually worked. | | | |
| ConstructionCosting | MaterialIssueResource | No | Stock that actually moved. | | | |
| ConstructionCosting | PlantItemResource | **Yes** | Another machine of the same kind and rates; its logs are not copied. | `code` | — | `registration`, `fixed_asset_id`, `commitment_id`, `created_by` |
| ConstructionCosting | PlantLogResource | No | Hours a machine actually ran. | | | |
| ConstructionCosting | RequisitionResource | **Yes** | A repeat request as a new draft with its lines; nothing on it is ordered. | — | `lines` | `number` (the year's next `REQ-`), `status`, `requested_by`, `requested_on` (today), `required_by`, `approved_*`, `rejection_reason` |
| ConstructionCosting | TradeResource | **Yes** | Master data. | `code` | — | — |
| ConstructionCosting | WorkerResource | No | A person (CNIC). | | | |
| ConstructionField | ActivityResource | No | A scheduled activity tied to a programme and its dependencies. | | | |
| ConstructionField | DailyLogResource | No | The site diary for a specific day. | | | |
| ConstructionField | DelayEventResource | No | An event that happened. | | | |
| ConstructionField | PunchListResource | No | Defects found on a specific inspection. | | | |
| ConstructionField | RfiResource | No | A numbered question to a specific party. | | | |
| ConstructionField | SubmittalResource | No | A numbered submission with a review trail. | | | |
| ConstructionQhse | IncidentResource | No | An event that happened. | | | |
| ConstructionQhse | InspectionResource | No | An inspection that happened. | | | |
| ConstructionQhse | ItpResource | **Yes** | A draft plan with the same activities and attending parties; inspections stay with the original's activities. | `reference` (within the job) | `activities` and each one's `parties` (in `afterReplication`) | `status`, `revision`, `issued_on`, `approved_by`, `approved_on`, `created_by` |
| ConstructionQhse | NcrResource | No | A non-conformance that was raised. | | | |
| ConstructionQhse | PermitResource | No | A permit issued for specific work. | | | |
| ConstructionQhse | QhseActionResource | No | A corrective action against a specific finding. | | | |
| ConstructionQhse | SitePersonnelResource | No | A person on site. | | | |
| ConstructionQhse | ToolboxTalkResource | No | A talk that was given, with attendance. | | | |
| Core | ActivityLogResource | No | The audit log. | | | |
| Core | CommentResource | No | Something a person said. | | | |
| Core | CustomFieldResource | **Yes** | A field definition; values are not copied. | `code` | — | — |
| Core | EmailTemplateResource | No | Keyed by the code that sends it; a copy's key is read by nothing. | | | |
| Core | FiscalYearResource | No | One per year, with periods and closing state. | | | |
| Core | HolidayResource | **Yes** | The same holiday on another date; the date is asked for with a date picker, suggesting the same day a year on. | `date` | — | — |
| Core | MessageLogResource | No | A record of a message sent. | | | |
| Core | OptionValueResource | **Yes** | An entry in an admin-managed list. | `value` | — | — |
| Core | ReportScheduleResource | **Yes** | A delivery setup; copied inactive and owned by whoever copied it. | — | — | `is_active`, `user_id` |
| Core | RoleResource | No | Platform role and permission set. | | | |
| Core | UserResource | No | Platform user (landlord). | | | |
| Core | WebhookDeliveryResource | No | A delivery attempt. | | | |
| Core | WebhookEndpointResource | No | Carries a signing secret. | | | |
| Core | WorkLocationResource | **Yes** | Master data; never a second head office. | `name` | — | `is_head_office` |
| Crm | FieldVisitRefusalResource | No | An event that happened. | | | |
| Crm | FieldVisitStaffResource | No | Assignment of a person to a visit. | | | |
| Crm | FieldVisitResource | No | A visit that happened. | | | |
| Crm | LeadSourceResource | **Yes** | Master data. | `name` | — | — |
| Crm | LeadResource | No | A real prospect; a copy is a duplicate lead. | | | |
| Crm | LostReasonResource | **Yes** | Master data. | `name` | — | — |
| Crm | NextActionResource | No | A reminder on a specific record. | | | |
| Crm | OpportunityResource | **Yes** | Another deal with the same lead or customer; it opens in the pipeline's first stage through OpportunityService, which records that entry. | — | — | `pipeline_stage_id` (first stage), `probability_pct` (the stage's), `closed_on`, `outcome`, `lost_reason_id`, `project_id`, `invoice_id`, `created_by` |
| Crm | PipelineResource | **Yes** | A sales pipeline with its stages; never a second default. | — | `stages` | `is_default` |
| Crm | SalesTargetResource | No | A target per person per period. | | | |
| Crm | WeeklySalesReportResource | No | A report for a specific week. | | | |
| Employees | AnnouncementResource | **Yes** | Reusing an announcement's text; the copy is unpublished. | — | — | `published_at`, `archived_at`, `pinned`, `created_by` |
| Employees | BusinessCardOrderResource | No | An order for a specific person. | | | |
| Employees | DepartmentResource | **Yes** | Master data. | `name` | — | — |
| Employees | DesignationResource | **Yes** | Master data. | `name` | — | — |
| Employees | EmployeeChangeRequestResource | No | A request about a specific employee. | | | |
| Employees | EmployeeSettingResource | No | Pay and tax settings of one employee. | | | |
| Employees | EmployeeResource | No | A person (CNIC, bank account, personal email). | | | |
| Employees | IdCardResource | No | A card issued to a person. | | | |
| Employees | LetterRequestResource | No | A request from a person. | | | |
| Employees | LetterTemplateResource | No | Unique per kind and locale: a copy could never be saved. | | | |
| Expenses | ExpenseCategoryResource | **Yes** | Master data. | `name` | — | — |
| Expenses | ExpenseClaimResource | **Yes** | A repeat claim as a draft dated today; it tells no approver and posts nothing until submitted. Receipts are not copied. | — | `lines` (less `receipt_path`) | `status` (draft), `claimed_on`, `receipt_path`, `submitted_by`, `submitted_at`, `decided_*`, `refusal_reason`, `payslip_id`, `payment_id`, `journal_entry_id` |
| Inventory | ProductResource | **Yes** | Catalogue item; stock movements are not copied. | `sku` | — | — |
| Inventory | StockLocationResource | **Yes** | Master data; movements are not copied. | `code` | — | `created_by` |
| Inventory | StockMovementResource | No | Stock that moved. | | | |
| Invoicing | ContactResource | **Yes** | A customer or supplier with its contact people; invoices and documents are not copied. | — | `people` | — |
| Invoicing | InvoiceLineResource | No | Copied with its invoice, never alone. | | | |
| Invoicing | InvoiceResource | **Yes** | Sales invoices and purchase bills become a new draft. Credit and debit notes refuse. | — | `lines` (less `cost_entry_id`, `commitment_line_id`) | `invoice_number`, `status`, `invoice_date`, `due_date`, `fiscal_year_id`, `amount_paid`, `journal_entry_id`, `recurring_invoice_id`, `period`, `credits_invoice_id`, `credit_reason`, `commissioner_approval_ref`, `commissioner_approved_on`, `fbr_status`, `fbr_irn`, `fbr_usin`, `fbr_reported_at`, `fbr_qr_payload` |
| Invoicing | TaxRateResource | **Yes** | Master data; never a second default. | — | — | `is_default` |
| Leave | LeaveEncashmentResource | No | Money paid. | | | |
| Leave | LeaveEntitlementResource | No | One per employee, type and year. | | | |
| Leave | LeaveGroupResource | **Yes** | A bundle of leave types; linked to the same types. | `name` | `types` | `is_default` |
| Leave | LeaveRequestResource | No | A request for specific days. | | | |
| Leave | LeaveTypeResource | **Yes** | Master data. | `code` | — | — |
| Lifecycle | ChecklistTemplateResource | **Yes** | A template with its items. | — | `items` | — |
| Lifecycle | EmployeeDocumentResource | No | A file belonging to a person. | | | |
| Lifecycle | EmploymentContractResource | No | A signed contract. | | | |
| Lifecycle | FinalSettlementResource | No | Money paid on leaving. | | | |
| Lifecycle | IssuedAssetResource | No | Something handed to a person. | | | |
| Lifecycle | SeparationResource | No | A person leaving. | | | |
| Mpr | MPRResource | No | A report for a specific month. | | | |
| Payroll | AnnualTaxResource | No | A tax computation for one employee and year. | | | |
| Payroll | PayAdjustmentResource | No | Pay for one employee in one month. | | | |
| Payroll | PayComponentResource | **Yes** | Payroll master data. | `code` | — | — |
| Payroll | PayrollRunResource | No | A run that produced payslips. | | | |
| Payroll | PayslipResource | No | Issued to a person. | | | |
| Payroll | ProvidentFundEntryResource | No | Posted contribution. | | | |
| Payroll | SalarySlabResource | **Yes** | A tax slab; next year's slabs start from this year's. | — | — | — |
| Payroll | SalaryStructureResource | **Yes** | A template with its lines. | `name` | `lines` | — |
| Performance | BadgeResource | **Yes** | Master data. | `name` | — | — |
| Performance | DailyReportResource | No | What one person did on one day. | | | |
| Performance | GoalResource | **Yes** | The same goal for another person or cycle; it starts open with nothing achieved. | — | — | `status`, `actual` |
| Performance | MeritEntryResource | No | Points awarded or deducted for an event. | | | |
| Performance | OneToOneResource | No | A meeting that happened. | | | |
| Performance | RecurringTaskResource | **Yes** | A standing obligation; copied inactive so nobody is penalised twice. | — | — | `is_active` |
| Performance | ReviewCycleResource | No | A cycle that opens reviews for everyone. | | | |
| Performance | ReviewTemplateResource | **Yes** | A template with its competencies. | `name` | `competencies` | `is_default` |
| Performance | ReviewResource | No | A person's appraisal. | | | |
| Performance | ScorecardProfileResource | **Yes** | Weights per designation. | `designation` | — | — |
| Performance | TaskOccurrenceResource | No | A generated occurrence of a recurring task. | | | |
| Projects | ProjectResource | **Yes** | Project setup; items, time and environments are not copied. | `code` | — | `status`, `key`, `next_item_number` |
| Quotations | QuotationResource | **Yes** | A new draft quotation with its lines. | — | `lines` | `number`, `status`, `version`, `supersedes_id`, `issue_date`, `valid_until`, `accepted_at`, `declined_at`, `decline_reason`, `invoice_id`, `created_by` |
| Recruitment | ApplicantResource | No | A person (CNIC, CV). | | | |
| Recruitment | ApplicationResource | No | A person applying to a vacancy. | | | |
| Recruitment | InterviewResource | No | A meeting that happened. | | | |
| Recruitment | VacancyResource | **Yes** | Reopening a similar role. | `code` | — | `status`, `opened_on`, `closed_on` |
| Support | TicketCategoryResource | **Yes** | Master data. | `name` | — | — |
| Support | TicketResource | No | A specific person's support request. | | | |
| Timesheets | TimesheetEntryResource | No | Hours actually worked. | | | |
| Work | IntegrationConnectionResource | No | Carries credentials and a webhook secret. | | | |
| Work | WorkItemResource | **Yes** | A similar bug or task in the same project. | — | `labels` (linked) | `key`, `number`, `work_status_id`, `rank` (from the project), `sprint_id`, `carried_over`, `started_at`, `resolved_at`, `resolution`, `archived_at`, `imported_from`, `ai_summary`, `ai_summary_at`, `assigned_at`, `reporter_user_id` |
| Work | WorkReleaseResource | No | A release with a ship date and notes. | | | |
| Work | WorkSprintResource | **Yes** | The next sprint in the same project: planned, no dates, no items. | `name` (within the project) | — | `state`, `starts_on`, `ends_on`, `started_at`, `closed_at` |
| Work | WorkWorkflowResource | **Yes** | A new workflow with the same statuses and rules; rules and the pull-request status point at the copy's own statuses. | `name` | `statuses`, `rules` (remapped in `afterReplication`) | `is_default`, `review_status_id` (remapped) |

## Decided in the second round

The first round left these out because of doubt. Each is now replicable, with the resets in the
table above: beneficiary, fixed asset, construction job, job budget, commitment, requisition, plant
item, ITP, holiday, opportunity, expense claim, goal, sprint and work workflow.

Two copies go deeper than one level of children, so they copy in `afterReplication()`:

- **ITP.** Each activity, then the parties who must attend it.
- **Work workflow.** The statuses first, then the rules and the pull-request status, each pointed at
  the copy's own statuses through a map of old id to new id. A copy that pointed back at the
  original's statuses would let one workflow's rule move an item into another workflow's status.

A unique **date** (a holiday) is asked for with a date picker, and its uniqueness is compared as a
date, because some drivers store a date column with a time.

## Replicate in the side sheet

A list that opens its records in the side sheet (docs/side-sheets.md) keeps Replicate as a row
action, and the sheet itself offers it in its footer, beside Save or Edit, because the sheet stands in
for the record's page and that page offers it in its header. `RecordSheetActions` adds it for any
model that implements `Replicable`; the action's own visibility (create permission,
`canBeReplicated()`) still decides.

## Still not offered

- **Bulk Replicate.** One record at a time, with the unique fields asked for each.
- **Custom field values on child rows.** Not copied. No child model currently carries custom fields.
