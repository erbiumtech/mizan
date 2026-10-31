# Side sheets

Records open from their list in a Material **side sheet** — Filament's slide-over modal, drawn by
`material.css` — instead of a separate page, where that is safe. The list stays behind it with its
filters, scroll position and page; on a phone the sheet fills the screen.

## The three choices

Every resource declares one, as `protected static RecordSheet $recordSheet`
(`App\Filament\Support\RecordSheet`):

| Choice | View | Edit |
| --- | --- | --- |
| `Sheet` | side sheet (only for someone who may not edit) | side sheet |
| `SheetView` | side sheet, with an **Edit** button to the full page | full page |
| `Page` | full page | full page |

The full pages stay registered whatever the choice, so links, bookmarks and tests keep working, and
every sheet has an **Open the full page** button in its header.

## How it works

- The list page uses `App\Filament\Concerns\OpensRecordsInSheets`. Filament gives a resource's
  view and edit actions the URL of their page whenever the page exists; the trait withholds it for the
  ones that open in the sheet, and Filament then opens them as a modal over the list. The row runs that
  action instead of linking to the page.
- The sheet is Filament's own `EditAction` / `ViewAction`, so the form, validation, policy checks and
  saving are Filament's. `App\Filament\Support\RecordSheetActions` only dresses it: the header
  (title, a status badge when the record has one, **Copy link**, **Open the full page**, close), the
  pinned Save / Cancel footer, and an edit sheet that does not close on a click outside it.
- The open sheet is written into Filament's own `?tableAction=edit&tableActionRecord=5` parameters,
  which Filament reads on load, so a refresh or a copied link reopens it. They clear when it closes.

## When a resource may be a `Sheet`

The edit sheet runs Filament's edit action, **not the resource's edit page**. So a resource is a
`Sheet` only when its edit page adds nothing to Filament's save:

- no lifecycle hooks on the edit page (`mutateFormDataBefore…`, `handleRecordUpdate`, `before…` /
  `after…`) and no `InteractsWithCustomFields`;
- no relation managers (the sheet has nowhere to show them);
- no repeaters or builders in the form;
- a form that does not read its Livewire page.

Beyond that, it should be master data — a lookup people fix in passing, not a document they work in.
Header actions on the edit page are still there, one click away on the full page.

`tests/Unit/RecordSheetCoverageTest.php` checks the declaration on every resource, the trait on the list
page of every sheet resource (and nowhere else), the rules above for every `Sheet`, and that this
document lists every resource.

## Every resource

29 `sheet`, 3 `sheet-view`, 132 `page` — 164 in all.

| Module | Resource | Opens in | Why |
| --- | --- | --- | --- |
| Accounting | `AccountResource` | page | Has relation managers. |
| Accounting | `BankFileFormatResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Accounting | `BankResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Accounting | `BankStatementLineResource` | page | Has relation managers. |
| Accounting | `BankStatementResource` | page | Has relation managers. |
| Accounting | `BeneficiaryResource` | page | Has relation managers, custom fields saved by the edit page. |
| Accounting | `BudgetResource` | page | Has relation managers, a repeater, edit-page logic (`mutateFormDataBeforeFill()`, `mutateFormDataBeforeSave()`, `afterSave()`). |
| Accounting | `ChequeBookResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Accounting | `ChequeLayoutResource` | page | A layout that needs room to place its fields. |
| Accounting | `ChequeResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Accounting | `CompanyBankAccountResource` | page | Has relation managers. |
| Accounting | `CurrencyResource` | page | Has relation managers. |
| Accounting | `FixedAssetResource` | page | Has relation managers, custom fields saved by the edit page. |
| Accounting | `JournalEntryLineResource` | page | A line of a posted document; changed with its journal entry. |
| Accounting | `JournalEntryResource` | page | Has relation managers. |
| Accounting | `LoanResource` | page | Has relation managers, edit-page logic (`beforeSave()`, `afterSave()`). |
| Accounting | `PaymentResource` | page | A money record with its own posting. |
| Accounting | `ScheduledTransactionResource` | page | Has a repeater. |
| Accounting | `TransactionTypeResource` | page | Has relation managers. |
| Advances | `AdvanceResource` | page | A money record with its own approval and recovery. |
| Advances | `AdvanceTypeResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Attendance | `AttendanceDayResource` | page | Has edit-page logic (`handleRecordUpdate()`). |
| Attendance | `AttendanceRegularizationResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Attendance | `EmployeeWorkPatternResource` | page | An employee assignment with effective dates, not a lookup. |
| Attendance | `OvertimeRequestResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Attendance | `RestDaySwapResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Attendance | `WorkPatternResource` | page | Has a repeater. |
| Billing | `BillingRunResource` | page | A run that produces invoices. |
| Campaigns | `CampaignResource` | page | Has relation managers. |
| Campaigns | `SegmentResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Construction | `CostCodeResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Construction | `JobResource` | page | An operational register record, not a lookup. |
| ConstructionContracts | `BackChargeResource` | page | Has edit-page logic (`afterSave()`). |
| ConstructionContracts | `ComplianceDocumentResource` | page | A document, usually with an upload. |
| ConstructionContracts | `ComplianceRequirementResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| ConstructionContracts | `ContractResource` | page | Has relation managers. |
| ConstructionContracts | `PaymentCertificateResource` | page | Has relation managers. |
| ConstructionContracts | `ProgressClaimResource` | page | Has relation managers. |
| ConstructionContracts | `RetentionMovementResource` | page | No edit page: the list is read-only or edits through its own actions. |
| ConstructionContracts | `VariationResource` | page | Has relation managers. |
| ConstructionCosting | `CommitmentResource` | page | Has relation managers. |
| ConstructionCosting | `ControlAccountResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| ConstructionCosting | `CostEntryResource` | page | Has edit-page logic (`handleRecordUpdate()`). |
| ConstructionCosting | `CostPeriodResource` | page | No edit page: the list is read-only or edits through its own actions. |
| ConstructionCosting | `GoodsReceiptResource` | page | Has relation managers. |
| ConstructionCosting | `JobBudgetResource` | page | Has relation managers. |
| ConstructionCosting | `LabourRateResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| ConstructionCosting | `LabourRecordResource` | page | Has edit-page logic (`handleRecordUpdate()`). |
| ConstructionCosting | `MaterialIssueResource` | page | Has relation managers. |
| ConstructionCosting | `PlantItemResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| ConstructionCosting | `PlantLogResource` | page | Has edit-page logic (`handleRecordUpdate()`). |
| ConstructionCosting | `RequisitionResource` | page | Has relation managers. |
| ConstructionCosting | `TradeResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| ConstructionCosting | `WorkerResource` | page | An operational register record, not a lookup. |
| ConstructionField | `ActivityResource` | page | Has relation managers. |
| ConstructionField | `DailyLogResource` | page | Has relation managers, edit-page logic (`handleRecordUpdate()`). |
| ConstructionField | `DelayEventResource` | page | A working record read and written at length. |
| ConstructionField | `PunchListResource` | page | Has relation managers. |
| ConstructionField | `RfiResource` | page | A working record read and written at length. |
| ConstructionField | `SubmittalResource` | page | Has relation managers. |
| ConstructionQhse | `IncidentResource` | page | Has relation managers, edit-page logic (`handleRecordUpdate()`). |
| ConstructionQhse | `InspectionResource` | page | Has relation managers. |
| ConstructionQhse | `ItpResource` | page | Has relation managers. |
| ConstructionQhse | `NcrResource` | page | Has relation managers, edit-page logic (`handleRecordUpdate()`). |
| ConstructionQhse | `PermitResource` | page | Has edit-page logic (`handleRecordUpdate()`). |
| ConstructionQhse | `QhseActionResource` | page | Has edit-page logic (`handleRecordUpdate()`). |
| ConstructionQhse | `SitePersonnelResource` | page | Has relation managers. |
| ConstructionQhse | `ToolboxTalkResource` | page | Has relation managers. |
| Core | `ActivityLogResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Core | `CommentResource` | page | Has relation managers. |
| Core | `CompanyResource` | page | Has relation managers, edit-page logic (`applyProfileAction()`, `applyProfileSummary()`, `modulesToGrant()`…). |
| Core | `ContactMessageResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Core | `CustomFieldResource` | page | Has relation managers. |
| Core | `EmailTemplateResource` | page | A template that needs room for its body. |
| Core | `FiscalYearResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Core | `HolidayResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Core | `MessageLogResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Core | `OptionValueResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Core | `PermissionResource` | page | Has edit-page logic (`afterSave()`). |
| Core | `PlatformActivityLogResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Core | `PlatformRoleResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Core | `PlatformUserResource` | page | A platform account; kept on its own page. |
| Core | `ReportScheduleResource` | page | A schedule that sends things; changed deliberately. |
| Core | `RoleResource` | page | Has edit-page logic (`mutateFormDataBeforeFill()`, `afterSave()`). |
| Core | `SitePageResource` | page | Has a form that reads its page. |
| Core | `UserResource` | page | Has edit-page logic (`mutateFormDataBeforeFill()`, `afterSave()`). |
| Core | `WebhookDeliveryResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Core | `WebhookEndpointResource` | page | Holds a signing secret; kept on its own page. |
| Core | `WorkLocationResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Crm | `FieldVisitRefusalResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Crm | `FieldVisitResource` | page | A working record read and written at length. |
| Crm | `FieldVisitStaffResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Crm | `LeadResource` | page | A working record read and written at length. |
| Crm | `LeadSourceResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Crm | `LostReasonResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Crm | `NextActionResource` | page | A working record read and written at length. |
| Crm | `OpportunityResource` | page | A working record read and written at length. |
| Crm | `PipelineResource` | page | Has a repeater. |
| Crm | `SalesTargetResource` | page | A plan per person and period, not a lookup. |
| Crm | `WeeklySalesReportResource` | page | A report written at length. |
| Employees | `AnnouncementResource` | page | Written at length and published. |
| Employees | `BusinessCardOrderResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Employees | `DepartmentResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Employees | `DesignationResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Employees | `EmployeeChangeRequestResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Employees | `EmployeeResource` | sheet-view | Read often from the list; editing has custom fields and relation managers, so it stays on the full page. |
| Employees | `EmployeeSettingResource` | page | Settings with effects across payroll and attendance. |
| Employees | `IdCardResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Employees | `LetterRequestResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Employees | `LetterTemplateResource` | page | A template that needs room for its body. |
| Expenses | `ExpenseCategoryResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Expenses | `ExpenseClaimResource` | page | Has a repeater. |
| Inventory | `ProductResource` | page | Has relation managers, custom fields saved by the edit page. |
| Inventory | `StockLocationResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Inventory | `StockMovementResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Invoicing | `ContactResource` | sheet-view | Looked up from the list; editing has custom fields and relation managers, so it stays on the full page. |
| Invoicing | `InvoiceLineResource` | page | A line of an invoice; changed with its invoice. |
| Invoicing | `InvoiceResource` | page | Has relation managers, custom fields saved by the edit page. |
| Invoicing | `TaxRateResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Leave | `LeaveEncashmentResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Leave | `LeaveEntitlementResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Leave | `LeaveGroupResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Leave | `LeaveRequestResource` | page | Has edit-page logic (`handleRecordUpdate()`). |
| Leave | `LeaveTypeResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Lifecycle | `ChecklistTemplateResource` | page | Has a repeater. |
| Lifecycle | `EmployeeDocumentResource` | page | A document about a person, usually with an upload. |
| Lifecycle | `EmploymentContractResource` | page | A contract about a person. |
| Lifecycle | `FinalSettlementResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Lifecycle | `IssuedAssetResource` | page | A record of something handed to a person. |
| Lifecycle | `SeparationResource` | page | Has relation managers. |
| Mpr | `MPRResource` | page | A report written at length. |
| Payroll | `AnnualTaxResource` | page | Has relation managers. |
| Payroll | `PayAdjustmentResource` | page | A payroll adjustment; changes pay. |
| Payroll | `PayComponentResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Payroll | `PayrollRunResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Payroll | `PayslipResource` | page | Has relation managers, edit-page logic (`handleRecordUpdate()`, `mutateFormDataBeforeFill()`). |
| Payroll | `ProvidentFundEntryResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Payroll | `SalarySlabResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Payroll | `SalaryStructureResource` | page | Has a repeater. |
| Performance | `BadgeResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Performance | `DailyReportResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Performance | `GoalResource` | page | A working record read and written at length. |
| Performance | `MeritEntryResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Performance | `OneToOneResource` | page | A working record read and written at length. |
| Performance | `RecurringTaskResource` | page | A schedule that creates tasks; changed deliberately. |
| Performance | `ReviewCycleResource` | page | A cycle that opens reviews; changed deliberately. |
| Performance | `ReviewResource` | page | A working record read and written at length. |
| Performance | `ReviewTemplateResource` | page | Has a repeater. |
| Performance | `ScorecardProfileResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Performance | `TaskOccurrenceResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Projects | `ProjectResource` | sheet-view | Looked up from the list; editing has environments (a repeater) and relation managers, so it stays on the full page. |
| Quotations | `QuotationResource` | page | Has a repeater, edit-page logic (`afterSave()`). |
| Recruitment | `ApplicantResource` | page | A working record read and written at length. |
| Recruitment | `ApplicationResource` | page | Has a custom row link. |
| Recruitment | `InterviewResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Recruitment | `VacancyResource` | page | A working record read and written at length. |
| Support | `TicketCategoryResource` | sheet | Master data: a short form, saved by Filament as it stands. |
| Support | `TicketResource` | page | A working record read and written at length. |
| Timesheets | `TimesheetEntryResource` | page | A time record people correct with care. |
| Work | `IntegrationConnectionResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Work | `WorkItemResource` | page | Has edit-page logic (`summariseAction()`, `suggestEstimateAction()`, `startTimerAction()`…). |
| Work | `WorkReleaseResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Work | `WorkSprintResource` | page | No edit page: the list is read-only or edits through its own actions. |
| Work | `WorkWorkflowResource` | page | No edit page: the list is read-only or edits through its own actions. |
