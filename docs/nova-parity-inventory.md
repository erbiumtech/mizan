# Nova → Filament Parity Inventory

Migration tracker for the MPR Laravel app. Every portable item is a checkbox so this doubles as a migration checklist.

**Scope:** 28 Nova resource classes, 28 Actions, 7 Filters, 7 Metrics, 1 custom Currency field, 1 Dashboard.

**Base class:** All resources extend `App\Nova\Resource` (which extends `Laravel\Nova\Resource`). The base adds no shared traits, fields, or authorization — its `indexQuery/scoutQuery/detailQuery/relatableQuery` overrides are pass-throughs. `indexQuery` is the extension point subclasses use for role-based scoping.

**Custom field:** `App\Nova\Fields\Currency` is used across many resources (see section 5) instead of the stock Nova field.

---

## 1. Resources

### 1. Account
- Model: `App\Models\Account` · Title: `title()` → `"{code} — {name}"` · Search: `code, name` · Group: `Accounting`
- Custom: `indexQuery()` reorders by `code`; `label()` → "Chart of Accounts"; `title()` override.
- No actions. No filters.

**Fields**
- [ ] ID — sortable
- [ ] Text `code` — required, max:20, unique (create + update-with-id), sortable
- [ ] Text `name` — required, max:255, sortable
- [ ] Select `type` — options asset/liability/equity/income/expense; displayUsingLabels; required; sortable; filterable
- [ ] Badge `normal_balance` — map debit→info, credit→warning; exceptOnForms
- [ ] BelongsTo `parent` → Account — nullable, searchable, sortable
- [ ] Boolean `is_active` (Active) — sortable, filterable
- [ ] Boolean `allow_manual_entry` — hideFromIndex
- [ ] Textarea `description` — nullable, hideFromIndex
- [ ] Currency `balance` — exceptOnForms, sortable
- [ ] HasMany `children` → Account (Sub Accounts)
- [ ] HasMany `lines` → JournalEntryLine (Ledger Lines)

### 2. ActivityLog
- Model: `Spatie\Activitylog\Models\Activity` · Title: `description` · Search: `id, description, log_name` · Group: `Audit`
- **Read-only:** `authorizedToCreate/Update/Delete/Replicate()` all → false. `label()` → "Activity Log".
- No actions. No filters.

**Fields**
- [ ] ID — sortable
- [ ] Text `log_name` (Model) — sortable, filterable
- [ ] Select `event` — options created/updated/deleted; displayUsingLabels; sortable; filterable
- [ ] Text `description` — onlyOnDetail
- [ ] MorphTo `subject` — onlyOnDetail
- [ ] Text "Causer" — computed (`causer?->name ?? 'System'`); sortable
- [ ] Code `attribute_changes` (Changes) — json; onlyOnDetail
- [ ] Code `properties` (Extra Properties) — json; onlyOnDetail
- [ ] DateTime `created_at` (When) — sortable, filterable

### 3. AnnualTax
- Model: `App\Models\AnnualTax` · Title: `id` · Search: via `searchableColumns()` · Group: `Taxes`
- Custom: `searchableColumns()` (id + relations employee.employee_id, employee.user.name, fiscalYear.name); `indexQuery()` multi-relation search.
- Filters: EmployeeFilter, FiscalYearFilter. No actions.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `employee` → Employee — searchable, sortable, required + custom closure preventing duplicate (employee_id + fiscal_year_id)
- [ ] BelongsTo `fiscalYear` → FiscalYear — required, searchable, sortable
- [ ] Number `total_net_income` — step 0.01, readonly, hideFromIndex
- [ ] Number `annual_income_tax` (Annual Taxable Income) — step 0.01, readonly
- [ ] Number `total_annual_tax` — step 0.01, readonly
- [ ] Number `paid_tax` — step 0.01, readonly
- [ ] Number `leftover_tax` — step 0.01, readonly

### 4. Bank
- Model: `App\Models\Bank` · Title: `bank_name` · Search: `bank_code, bank_name, bank_short_code` · Group: `Accounting`
- No actions, filters, or custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `bank_code` — required, max:20, unique (create + update-with-id), sortable, help (IMD code)
- [ ] Text `bank_name` — required, max:255, sortable
- [ ] Text `bank_short_code` — nullable, max:20, sortable, help
- [ ] Boolean `is_active` (Active) — sortable
- [ ] HasMany `employees` → Employee

### 5. BankStatement
- Model: `App\Models\BankStatement` · Title: `title()` → `"Statement #{id}"` · Search: `id` · Group: `Accounting`
- Custom: `title()` override.
- Actions: ImportStatementLines (canRun 'import'), AutoMatchStatement (showInline, 'match'), CompleteReconciliation (showInline, 'complete'). No filters.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `account` → Account (Bank Account) — relatableQueryUsing (postable, type asset); required
- [ ] Date `statement_date` — required, sortable
- [ ] Currency `opening_balance` — numeric
- [ ] Currency `closing_balance` — numeric
- [ ] Badge `status` — map draft→info, in_progress→warning, completed→success; sortable, filterable
- [ ] Text "Progress" — computed (matched/total); exceptOnForms
- [ ] BelongsTo `completedBy` → User — onlyOnDetail
- [ ] DateTime `completed_at` — onlyOnDetail
- [ ] HasMany `lines` → BankStatementLine

### 6. BankStatementLine
- Model: `App\Models\BankStatementLine` · Title: `description` · Search: `description, reference` · Group: `Accounting` · `$displayInNavigation = false`
- Actions: MatchStatementLine, UnmatchStatementLine, ExcludeStatementLine — all showInline, canRun 'match'. No filters.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `bankStatement` → BankStatement (Statement) — exceptOnForms
- [ ] Date `transaction_date` — required, sortable
- [ ] Text `description` — nullable
- [ ] Text `reference` — nullable
- [ ] Currency `amount` — required, numeric, help (signed +in/-out)
- [ ] Badge `match_status` — map unmatched→danger, auto_matched→success, manually_matched→success, excluded→info; sortable, filterable
- [ ] BelongsTo `matchedLine` → JournalEntryLine (Matched Ledger Line) — nullable, exceptOnForms

### 7. Beneficiary
- Model: `App\Models\Beneficiary` · Title: `name` · Search: `name, account_no, iban, id_number` · Group: `Accounting`
- No actions, filters, or custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `name` — required, max:255, sortable, help
- [ ] BelongsTo `bank` → Bank — nullable, searchable
- [ ] Text `account_no` — nullable, hideFromIndex
- [ ] Text `iban` (IBAN) — nullable, max:34
- [ ] Select `id_type` (ID Type) — options CNIC, NTN; nullable; displayUsingLabels; hideFromIndex
- [ ] Text `id_number` — nullable, hideFromIndex
- [ ] Text `address_line_1` — nullable, hideFromIndex
- [ ] Text `address_line_2` — nullable, hideFromIndex
- [ ] Text `email` — nullable, email, hideFromIndex
- [ ] Text `phone` — nullable, hideFromIndex
- [ ] BelongsTo `transactionType` → TransactionType (Usual Transaction Type) — nullable, help
- [ ] Select `payment_type` (Default Payment Type) — options IBFT/BT/ACH/RTGS/LBC; default IBFT; required
- [ ] Boolean `is_active` (Active) — sortable
- [ ] HasMany `payments` → Payment

### 8. Comment
- Model: `App\Models\Comment` · Title: `body` · Search: `body` · Group: `Audit` · `$displayInNavigation = false`
- Actions: ResolveComment (showInline, canRun `can('resolve', $comment)`). No filters.

**Fields**
- [ ] ID — sortable
- [ ] MorphTo `commentable` (On) — types [Payslip]; exceptOnForms
- [ ] BelongsTo `user` (By) → User — default current user id; exceptOnForms
- [ ] Textarea `body` (Comment) — required, alwaysShow
- [ ] Badge "Status" — computed (open/resolved); map open→warning, resolved→success
- [ ] BelongsTo `resolver` (Resolved By) → User — onlyOnDetail
- [ ] DateTime `created_at` (Created) — exceptOnForms, sortable

### 9. CompanyBankAccount
- Model: `App\Models\CompanyBankAccount` · Title: `title` · Search: `title, account_no, iban` · Group: `Accounting`
- No actions, filters, or custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `title` (Title) — required, max:255, sortable
- [ ] BelongsTo `bank` → Bank — nullable, searchable
- [ ] Text `account_no` (index variant) — required, max:50, displayUsing masks all but last 4 with `•`, onlyOnIndex
- [ ] Text `account_no` (form/detail variant) — required, max:50, hideFromIndex
- [ ] Text `iban` (IBAN) — nullable, max:34, hideFromIndex
- [ ] BelongsTo `transactionType` (Purpose) → TransactionType — nullable, help
- [ ] Boolean `is_default` (Default for its type) — help
- [ ] Boolean `is_active` (Active) — sortable

### 10. Contact
- Model: `App\Models\Contact` · Title: `name` · Search: `name, email, ntn` · Group: `Invoicing`
- No actions, filters, or custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `name` — required, max:255, sortable
- [ ] Badge `kind` (Kind) — map customer→info, supplier→warning, both→success; sortable
- [ ] Select `kind` (Kind) — options customer/supplier/both; required; onlyOnForms
- [ ] Text `email` — nullable, email, hideFromIndex
- [ ] Text `phone` — nullable, hideFromIndex
- [ ] Text `address_line_1` — nullable, hideFromIndex
- [ ] Text `address_line_2` — nullable, hideFromIndex
- [ ] Text `ntn` (NTN) — nullable, hideFromIndex
- [ ] Text `cnic` (CNIC) — nullable, hideFromIndex
- [ ] BelongsTo `bank` → Bank — nullable, hideFromIndex, help
- [ ] Boolean `is_active` (Active) — sortable
- [ ] HasMany `invoices` → Invoice

### 11. Employee
- Model: `App\Models\Employee` · Title: `title()` → `"{employee_id} - {user.name}"` · Search: `employee_id` (+ searchableColumns) · Group: `Employee`
- Custom: `title()`; `searchableColumns()`; `indexQuery()` (Admins all, others scoped to own user_id); `relatableUsers()` (BelongsTo user picker restricted to role Employee). `$adminOnly` closure drives `readonly` on many fields, `canSee` Admins on ID.
- Filters: EmployeeNameFilter, EmployeeEmailFilter. No actions.

**Fields**
- [ ] ID — sortable, canSee Administrators only
- [ ] Text `employee_id` (Employee ID) — required, readonly($adminOnly)
- [ ] BelongsTo `user` (Employee Name) → User — sortable, readonly
- [ ] Text `user_name` (Name) — resolveUsing user.name, custom fillUsing; required, max:255; onlyOnForms
- [ ] Text `user_email` (Email) — resolveUsing user.email, custom fillUsing; required, email + closure enforcing email uniqueness across users
- [ ] Select `is_active` (Status) — options 1→Active, 0→Inactive; displayUsingLabels; readonly($adminOnly)
- [ ] Select `designation` — options (Senior Full Stack Dev, Full Stack Dev, Frontend Dev, Backend Dev, Secretary, Cook, Office Boy); displayUsingLabels; required; readonly($adminOnly); hideFromIndex
- [ ] Select `department` — options IT, Office Staff; displayUsingLabels; required; readonly($adminOnly); hideFromIndex
- [ ] Date `date_of_joining` — hideFromIndex
- [ ] Text `nic` (NIC) — hideFromIndex
- [ ] BelongsTo `bank` → Bank — nullable, searchable, hideFromIndex, help
- [ ] Text `bank_code` — readonly, exceptOnForms
- [ ] Text `bank_short_code` — readonly, exceptOnForms
- [ ] Text `bank_account_no` (Bank A/C No) — hideFromIndex
- [ ] Text `iban_no` (IBAN No) — hideFromIndex
- [ ] Text `phone` — hideFromIndex
- [ ] Text `address_line_1` — hideFromIndex
- [ ] Text `address_line_2` — hideFromIndex
- [ ] Select `gender` (Gender) — options Male, Female, Other; hideFromIndex
- [ ] HasMany `changeRequests` → EmployeeChangeRequest

### 12. EmployeeChangeRequest
- Model: `App\Models\EmployeeChangeRequest` · Title: `id` · Search: `id` · Group: `Employee`
- **Authorization:** `authorizedToCreate()` → false, `authorizedToUpdate()` → false, `authorizedToDelete()` → Admins only. `menu()` shows a sidebar badge with pending count (needs `EmployeeChangeApprove` perm). `indexQuery()` scopes non-approvers to their own `requested_by`.
- Actions: ApproveEmployeeChange, RejectEmployeeChange. No filters.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `employee` → Employee — sortable
- [ ] BelongsTo `requester` (Requested By) → User
- [ ] KeyValue `requested_changes` (Requested Changes) — keyLabel Field, valueLabel New Value
- [ ] KeyValue `original_values` (Current Values) — onlyOnDetail
- [ ] Badge `status` — map pending→warning, approved→success, rejected→danger; sortable
- [ ] BelongsTo `reviewer` (Reviewed By) → User — nullable, onlyOnDetail
- [ ] DateTime `reviewed_at` — onlyOnDetail
- [ ] Text `rejection_reason` — onlyOnDetail
- [ ] DateTime `created_at` (Requested At) — sortable, exceptOnForms

### 13. EmployeeSetting
- Model: `App\Models\EmployeeSetting` · Title: `id` · Search: via `searchableColumns()` · Group: `Employee`
- Custom: `searchableColumns()` (id + employee.employee_id, employee.user.name, fiscalYear.name); `indexQuery()` cross-relation LIKE search.
- Filters: EmployeeFilter, FiscalYearFilter. No actions.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `employee` → Employee — searchable, sortable, required
- [ ] BelongsTo `fiscalYear` → FiscalYear — required, relatableQueryUsing (is_active = true)
- [ ] Date `start_date` — required, default today, displayUsing m/Y, sortable
- [ ] Date `end_date` — nullable, displayUsing m/Y, sortable
- [ ] Number `basic_wage` (Basic Wage) — step 0.01, default 0
- [ ] Number `medical_allowance` — step 0.01, default 0
- [ ] Number `device_allowance` — step 0.01, default 0, hideFromIndex
- [ ] Number `petrol_allowance` — step 0.01, default 0, hideFromIndex
- [ ] Number `bonus` — step 0.01, default 0, hideFromIndex
- [ ] Number `extra_work_hours` — step 0.01, default 0, help, hideFromIndex
- [ ] Number `advances` — step 0.01, default 0, help, hideFromIndex
- [ ] Number `meal_deduction` — step 0.01, default 0, hideFromIndex
- [ ] Number `esi_health_insurance` (ESI / Health Insurance) — step 0.01, default 0, hideFromIndex

### 14. FiscalYear
- Model: `App\Models\FiscalYear` · Title: `name` · Search: `name` · Group: `Salary Slab & Fiscal Year`
- No actions, filters, or custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `name` (Year Name) — required
- [ ] Boolean `is_active` (Is Active) — default true

### 15. FixedAsset
- Model: `App\Models\FixedAsset` · Title: `title()` → `"{asset_code} — {name}"` · Search: `asset_code, name` · Group: `Accounting`
- Custom: `title()` override.
- Actions: RunDepreciation (showInline, canRun 'depreciate'), DisposeFixedAsset (showInline, canRun 'dispose'). No filters.

**Fields**
- [ ] ID — sortable
- [ ] Text `asset_code` — exceptOnForms, sortable
- [ ] Text `name` — required, max:255, sortable
- [ ] BelongsTo `account` (Asset Account) → Account — relatableQueryUsing (postable, type asset); required
- [ ] Date `purchase_date` — required, sortable
- [ ] Currency `purchase_cost` — required, numeric, min:0.01
- [ ] Select `depreciation_method` — options straight_line, declining_balance; displayUsingLabels; default straight_line; hideFromIndex
- [ ] Number `useful_life_months` — required, integer, min:1, hideFromIndex
- [ ] Currency `salvage_value` — numeric, min:0, hideFromIndex
- [ ] Currency `accumulated_depreciation` — exceptOnForms
- [ ] Currency `book_value` — computed, exceptOnForms, sortable
- [ ] Badge `status` — map active→success, fully_depreciated→warning, disposed→danger; sortable, filterable
- [ ] DateTime `disposed_at` — onlyOnDetail
- [ ] MorphMany `journalEntries` → JournalEntry

### 16. Invoice
- Model: `App\Models\Invoice` · Title: `invoice_number` · Search: `invoice_number` · Group: `Invoicing`
- Actions: IssueInvoice, RecordInvoicePayment, VoidInvoice. No filters. No custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `invoice_number` (Number) — sortable, exceptOnForms
- [ ] Badge `kind` (Kind) — map sale→info, purchase→warning; sortable
- [ ] Select `kind` (Kind) — options sale/purchase; required; onlyOnForms; hideWhenUpdating
- [ ] BelongsTo `contact` → Contact — sortable
- [ ] Date `invoice_date` — required, sortable
- [ ] Date `due_date` — nullable, hideFromIndex
- [ ] Badge `status` — map draft→warning, issued→info, partially_paid→info, paid→success, void→danger; sortable
- [ ] Currency `subtotal` — PKR, required, numeric, min:0, hideFromIndex
- [ ] Currency `tax_amount` (Tax) — PKR, required, numeric, min:0, hideFromIndex
- [ ] Currency `total` (Total) — PKR, required, numeric, min:0, sortable
- [ ] Currency `amount_paid` (Paid) — PKR, exceptOnForms, sortable
- [ ] Currency `outstanding` — computed, PKR, exceptOnForms
- [ ] Text `memo` — nullable, hideFromIndex
- [ ] BelongsTo `journalEntry` → JournalEntry — nullable, exceptOnForms
- [ ] URL (computed `/reports/invoice/{id}/pdf`) — displayUsing "Download PDF", onlyOnDetail
- [ ] HasMany `lines` → InvoiceLine

### 17. InvoiceLine
- Model: `App\Models\InvoiceLine` · Title: `description` · Search: `description` · Group: `Invoicing` · `$displayInNavigation = false`
- **Authorization:** `authorizedToCreate()` → can 'InvoiceUpdate'; `authorizedToUpdate()` → invoice isDraft() AND can 'InvoiceUpdate'; `authorizedToDelete()` → same as update. No actions/filters.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `invoice` → Invoice
- [ ] BelongsTo `product` → Product — nullable, help
- [ ] Text `description` — required, max:255
- [ ] Number `quantity` — step 0.01, required, numeric, min:0.01
- [ ] Currency `unit_price` — PKR, required, numeric, min:0
- [ ] Currency `line_total` — PKR, required, numeric, min:0
- [ ] BelongsTo `account` (Account Override) → Account — nullable, hideFromIndex, help

### 18. JournalEntry
- Model: `App\Models\JournalEntry` · Title: `entry_number` · Search: `entry_number, reference, memo` · Group: `Accounting`
- Actions: SubmitJournalEntry, ApproveJournalEntry, RejectJournalEntry, PostJournalEntry, ReverseJournalEntry — all showInline, each canRun gated on a policy ability. No filters. No custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `entry_number` — exceptOnForms, sortable
- [ ] Date `entry_date` — required, sortable
- [ ] Select `entry_type` — options general/adjusting/closing/reversing; displayUsingLabels; default general; filterable
- [ ] Badge `status` — map draft→info, pending_approval→warning, approved→success, rejected→danger, posted→success; sortable, filterable
- [ ] Text `reference` — nullable, hideFromIndex
- [ ] Textarea `memo` — nullable, alwaysShow
- [ ] Text `rejection_reason` — onlyOnDetail
- [ ] BelongsTo `fiscalYear` → FiscalYear — nullable
- [ ] BelongsTo `creator` (Created By) → User — exceptOnForms
- [ ] BelongsTo `approver` (Approved By) → User — onlyOnDetail
- [ ] DateTime `approved_at` — onlyOnDetail
- [ ] DateTime `posted_at` — onlyOnDetail
- [ ] Currency `total_debits` — computed, onlyOnDetail
- [ ] Currency `total_credits` — computed, onlyOnDetail
- [ ] HasMany `lines` → JournalEntryLine

### 19. JournalEntryLine
- Model: `App\Models\JournalEntryLine` · Title: `id` · Search: `id, description` · Group: `Accounting` · `$displayInNavigation = false`
- **Authorization:** `authorizedToCreate()` → can 'create' JournalEntry. No actions/filters.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `journalEntry` → JournalEntry
- [ ] BelongsTo `account` → Account — searchable, relatableQueryUsing (is_active, allow_manual_entry, no children)
- [ ] Currency `debit_amount` (Debit) — required, numeric, min:0, default 0
- [ ] Currency `credit_amount` (Credit) — required, numeric, min:0, default 0
- [ ] Text `description` — nullable

### 20. MPR
- Model: `App\Models\MPR` · Title: default `id` · Search: `id, user.name` · Group: `MPR` · `label()` → "MPR"
- Custom: `label()`; `indexQuery()` (Admins all, others scoped to own user_id).
- Actions: DownloadSingleMprPdf (showInline, showOnDetail, withoutConfirmation), DownloadMprPdf (standalone). Filters: UserNameFilter.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `user` → User — sortable, required
- [ ] Date `mpr_date` (Date) — sortable, required, default now(), displayUsing d-m-Y
- [ ] Trix `feedback` — required, hideFromIndex, withFiles('public','Mpr')
- [ ] Trix `topics_scope` (Topics & Scope) — required, hideFromIndex, withFiles
- [ ] Trix `recent_module` (Recent Module) — required, hideFromIndex, withFiles
- [ ] Trix `employee_request` (Employee Request) — required, hideFromIndex, withFiles
- [ ] Trix `next_mpr_goal` (Next Mpr Goal) — required, hideFromIndex, withFiles
- [ ] Trix `current_month_learning` (What have you learnt this month?) — required, hideFromIndex, withFiles

### 21. Payment
- Model: `App\Models\Payment` · Title: `details` · Search: `details, reference` · Group: `Accounting`
- Actions: ApprovePayment. No filters. No custom methods.

**Fields**
- [ ] ID — sortable
- [ ] MorphTo `payable` (Payable) — types [Employee, Beneficiary]; searchable; help
- [ ] BelongsTo `transactionType` (Transaction Type) → TransactionType
- [ ] BelongsTo `companyBankAccount` (Debit Account) → CompanyBankAccount — nullable, help
- [ ] Currency `amount` — PKR, sortable, required, numeric, min:0.01
- [ ] Text `details` — required, max:140, help
- [ ] Text `reference` — nullable, hideFromIndex
- [ ] Date `value_date` — nullable
- [ ] Select `payment_type` — options IBFT/BT/ACH/RTGS/LBC; nullable; displayUsing resolvedPaymentType(); readonly when exists AND status !== DRAFT; help
- [ ] Badge `status` — map draft→warning, approved→info, exported→success, paid→success; sortable
- [ ] BelongsTo `journalEntry` → JournalEntry — nullable, exceptOnForms

### 22. Payslip
- Model: `App\Models\Payslip` · Title: `id` · Search: via `searchableColumns()` · Group: `Payslip`
- **Heaviest resource.** Custom: `searchableColumns()`; `indexQuery()` (role-based scoping — plain Employees see only their own payslips; Admin/Accountant/Manager/CEO see all + multi-relation search); `updateCalculatedFields()` helper calling `PayslipService::calculateByParams()`. Most numeric fields are readonly and reactively recomputed via `dependsOn` on [employee, month, fiscalYear] + inputs.
- Actions: DownloadPayslip (showOnTableRow, withoutConfirmation), AcceptPayslip, RejectPayslip. Filters: EmployeeFilter, MonthFilter, FiscalYearFilter.

**Fields**
- [ ] ID — sortable, hideFromIndex
- [ ] BelongsTo `employee` → Employee — searchable, required + closure preventing duplicate (employee/month/fiscal_year)
- [ ] Select `month` — options Jan–Dec; required
- [ ] BelongsTo `fiscalYear` → FiscalYear — required, relatableQueryUsing (is_active)
- [ ] Text `total_working_days` — required, numeric, min:0, default 0, hideFromIndex
- [ ] Text `paid_days` — required, numeric, min:0, default 0, hideFromIndex
- [ ] Text `lop_days` (LOP Days) — required, numeric, min:0, default 0, hideFromIndex
- [ ] Text `leaves_taken` — required, numeric, min:0, default 0, hideFromIndex
- [ ] Text `basic_wage` — readonly, dependsOn (calculated), hideFromIndex
- [ ] Text `medical_allowance` — readonly, dependsOn (calculated), hideFromIndex
- [ ] Text `device_allowance` — nullable, numeric, min:0, dependsOn, hideFromIndex
- [ ] Text `petrol_allowance` — nullable, numeric, min:0, dependsOn, hideFromIndex
- [ ] Text `bonus` — nullable, numeric, min:0, dependsOn, hideFromIndex
- [ ] Text `extra_work_hours` — nullable, numeric, min:0, dependsOn, hideFromIndex
- [ ] Text `advances` — nullable, numeric, min:0, dependsOn, hideFromIndex
- [ ] Text `meal_deduction` — nullable, numeric, min:0, dependsOn, hideFromIndex
- [ ] Text `esi_health_insurance` — nullable, numeric, min:0, dependsOn, hideFromIndex
- [ ] Text `withholding_tax` — readonly, dependsOn (calculated), hideFromIndex
- [ ] Text `total_earnings` — readonly, dependsOn (calculated), hideFromIndex
- [ ] Text `total_deductions` — readonly, dependsOn (calculated), hideFromIndex
- [ ] Text `net_salary` — readonly, sortable, dependsOn (calculated), shown on index
- [ ] Badge `employee_review` — map pending→warning, accepted→success, rejected→danger; sortable, exceptOnForms
- [ ] DateTime `employee_reviewed_at` (Reviewed At) — onlyOnDetail
- [ ] Text `employee_rejection_reason` (Rejection Reason) — onlyOnDetail
- [ ] MorphMany `comments` → Comment

### 23. Product
- Model: `App\Models\Product` · Title: `name` · Search: `sku, name` · Group: `Inventory`
- Actions: ReceiveStock, RecordSale, AdjustStock. No filters. No custom methods. (`InventoryValuationService` injected in `fields()`.)

**Fields**
- [ ] ID — sortable
- [ ] Text `sku` (SKU) — required, max:50, unique (create + update-with-id), sortable
- [ ] Text `name` — required, max:255, sortable
- [ ] Text `description` — nullable, hideFromIndex
- [ ] Text `unit` — required, max:20, hideFromIndex
- [ ] Badge `valuation_method` (Valuation) — map fifo→info, lifo→warning, average→success; label uppercased; sortable
- [ ] Select `valuation_method` (Valuation Method) — options fifo/lifo/average; required; onlyOnForms; help
- [ ] Number "On Hand" — computed (`valuation->onHand()`); exceptOnForms
- [ ] Currency "Stock Value" — computed (`valuation->stockValue()`); PKR; exceptOnForms
- [ ] Number `reorder_level` — step 0.01, hideFromIndex
- [ ] Badge "Stock Status" — computed low/ok; map low→danger, ok→success; exceptOnForms
- [ ] BelongsTo `inventoryAccount` → Account — nullable, hideFromIndex, help (default 1300)
- [ ] BelongsTo `cogsAccount` → Account — nullable, hideFromIndex, help (default 5050)
- [ ] BelongsTo `revenueAccount` → Account — nullable, hideFromIndex, help (default 4200)
- [ ] Boolean `is_active` (Active) — sortable
- [ ] HasMany `movements` → StockMovement

### 24. SalarySlab
- Model: `App\Models\SalarySlab` · Title: `id` · Search: `id, fiscalYear.name` · Group: `Salary Slab & Fiscal Year`
- Filters: FiscalYearFilter. No actions. `cards()` and `lenses()` empty.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `fiscalYear` → FiscalYear — required, relatableQueryUsing (is_active)
- [ ] Number `min_amount` (Minimum Amount Annual) — required, numeric, min:0, sortable, help
- [ ] Number `max_amount` (Maximum Amount Annual) — nullable, help
- [ ] Number `fixed_tax` (Fixed Tax Amount) — required, numeric, min:0, default 0, help
- [ ] Number `percentage` (Tax Percentage %) — required, numeric, min:0, max:100, default 0, step 0.01, help
- [ ] Text "Slab Preview" — computed; hideFromIndex

### 25. StockMovement
- Model: `App\Models\StockMovement` · Title: `id` · Search: `reference` · Group: `Inventory`
- **Read-only:** `authorizedToCreate/Update/Delete()` all → false (records created via InventoryService). No actions/filters.

**Fields**
- [ ] ID — sortable
- [ ] BelongsTo `product` → Product — sortable
- [ ] Badge `type` — map purchase→success, sale→info, adjustment→warning; sortable
- [ ] Number `quantity` — sortable
- [ ] Currency `unit_cost` — PKR
- [ ] Currency `unit_price` — PKR
- [ ] Currency `total_cost` (COGS) — PKR
- [ ] Number `remaining_quantity` (Lot Remaining) — hideFromIndex
- [ ] Date `movement_date` (Date) — sortable
- [ ] Text `reference` — hideFromIndex
- [ ] BelongsTo `journalEntry` → JournalEntry — nullable

### 26. TransactionType
- Model: `App\Models\TransactionType` · Title: `name` · Search: `name, code` · Group: `Accounting`
- No actions, filters, or custom methods.

**Fields**
- [ ] ID — sortable
- [ ] Text `name` — required, max:100, unique (create + update-with-id), sortable
- [ ] Text `code` — required, max:50, unique (create + update-with-id), sortable, help
- [ ] BelongsTo `account` (Default Account) → Account — nullable, searchable, help
- [ ] Text `description` — nullable, hideFromIndex
- [ ] Boolean `is_active` (Active) — sortable
- [ ] HasMany `payments` → Payment
- [ ] HasMany `companyBankAccounts` → CompanyBankAccount

### 27. User
- Model: `App\Models\User` · Title: `name` · Search: `id, name, email` · Group: `User`
- **Side-effect hook:** `afterCreate()` syncs role `Employee` to the new user AND auto-creates an `Employee` record (`employee_id = 'EMP-'.$id`, is_active=1). Filters: UserNameFilter, UserEmailFilter. No actions.

**Fields**
- [ ] ID — sortable
- [ ] Text `name` — required, max:255
- [ ] Email `email` — required, email, max:255, unique (create + update-with-id)
- [ ] Password `password` — creationRules required/min:8, updateRules nullable/min:8, hideFromIndex

### 28. Resource (base)
- Abstract `App\Nova\Resource`. No shared traits/fields/authorization. Query hooks (`indexQuery`, `scoutQuery`, `detailQuery`, `relatableQuery`) are pass-throughs. Nothing to port beyond awareness that subclasses override `indexQuery` for scoping.

---

## 2. Actions (28)

- [x] **AcceptPayslip** ("Accept Payslip") — records employee-accepted review via `recordEmployeeReview(ACCEPTED)`.
- [x] **RejectPayslip** ("Reject Payslip") — records employee objection via `recordEmployeeReview(REJECTED, reason)`. Field: Textarea `reason` (required, max 255).
- [x] **DownloadPayslip** ("Download Payslip") — generates payslip PDF (Browsershot) if missing, stores `pdf_path`, returns download.
- [x] **ApproveEmployeeChange** ("Approve") — `$request->approve(user)` for each pending change request.
- [x] **RejectEmployeeChange** ("Reject") — `$request->reject(user, reason)`. Field: Text `reason` (nullable).
- [x] **SubmitJournalEntry** ("Submit for Approval") — `service->submitForApproval(entry)`.
- [x] **ApproveJournalEntry** ("Approve") — `service->approve(entry, user)`.
- [x] **RejectJournalEntry** ("Reject") — `service->reject(entry, user, reason)`. Field: Textarea `reason` (required).
- [x] **PostJournalEntry** ("Post Entry") — `service->post(entry)` posts to ledger / updates balances. Confirm: "Posting will update account balances… only reversed."
- [x] **ReverseJournalEntry** ("Reverse Entry") — `service->reverse(entry, user)` creates+posts a mirrored reversing entry. Confirm text.
- [x] **IssueInvoice** ("Issue") — `service->issue(invoice)`.
- [x] **RecordInvoicePayment** ("Record Payment") — `service->recordPayment(invoice, amount, date)`. Fields: Date `date`, Number `amount` (required, min 0.01).
- [x] **VoidInvoice** ("Void") — `service->void(invoice, user)`.
- [x] **ApprovePayment** ("Approve Payment") — for draft payments, `PaymentService->approve(payment)` (approves + books journal entry).
- [x] **ReceiveStock** ("Receive Stock") — `service->purchase(product, qty, unit_cost, date, ref)`. Fields: Date, Number qty, Number unit_cost (req), Text reference.
- [x] **RecordSale** ("Record Sale") — `service->sale(product, qty, unit_price, date, ref)`. Fields: Date, Number qty, Number unit_price, Text reference.
- [x] **AdjustStock** ("Adjust Stock") — `service->adjust(product, qty, date, unit_cost, ref)`. Fields: Date, Number qty, Number unit_cost (nullable), Text reference.
- [x] **RunDepreciation** ("Run Depreciation") — books one month of depreciation via `service->depreciateAsset(asset, month, fiscalYearId)`. Field: Date `month` (default last month). Confirm text.
- [x] **DisposeFixedAsset** ("Dispose Asset") — `service->dispose(asset)` writes asset off. Confirm text (cannot be undone).
- [x] **ImportStatementLines** ("Import Lines (CSV)") — parses pasted CSV, `service->import(rows, statement)`. Field: Textarea `csv` (required).
- [x] **AutoMatchStatement** ("Auto-Match") — `service->autoMatch(statement)` matches unmatched lines. Confirm text.
- [x] **MatchStatementLine** ("Match") — `service->match(line, ledgerLine)`. Field: Select `ledger_line_id` (searchable, unreconciled posted lines, required).
- [x] **UnmatchStatementLine** ("Unmatch") — `service->unmatch(line)` undoes a match.
- [x] **ExcludeStatementLine** ("Exclude") — `service->exclude(line)`. Confirm text.
- [x] **CompleteReconciliation** ("Complete Reconciliation") — `service->complete(statement, user)` finalizes/locks. Confirm text (all lines matched/excluded, balances equal).
- [x] **DownloadMprPdf** ("Generate / Download PDF") — `generateComparisonReport(userId)` (recent two MPRs), saves PDF, opens in new tab. Field: Select `user_id` (own only for non-admins; all active users for admins).
- [x] **DownloadSingleMprPdf** ("Download PDF") — reuses `pdf_path` or `generateSingleReport(record)`, opens in new tab.
- [x] **ResolveComment** ("Mark Resolved") — sets `resolved_at = now()`, `resolved_by = current user`.

**Notes:** Confirmation dialogs on PostJournalEntry, ReverseJournalEntry, RunDepreciation, DisposeFixedAsset, AutoMatchStatement, ExcludeStatementLine, CompleteReconciliation. Most actions delegate to a service class, wrap in try/catch returning `Action::danger()`, and gate execution via `authorizedToRun()` policy checks — these must map to Filament Action `->visible()`/`->authorize()` + `->requiresConfirmation()`.

---

## 3. Filters (7)

All extend `Laravel\Nova\Filters\Filter` (render as select dropdowns; none are BooleanFilter).

- [x] **EmployeeEmailFilter** ("Employee Email") — `where('id', $value)`; options `[user.email ?? 'No Email' => employee.id]`.
- [x] **EmployeeFilter** ("Employee") — `where('employee_id', $value)`; options `["{employee_id} - {user.name}" => employee.id]`.
- [x] **EmployeeNameFilter** ("Employee Name") — `where('id', $value)`; options `[user.name ?? 'Unknown' => employee.id]`.
- [x] **FiscalYearFilter** ("Fiscal Year") — `where('fiscal_year_id', $value)`; options `[name => id]`.
- [x] **MonthFilter** ("Month") — `where('month', $value)`; options static January–December (label == value).
- [x] **UserEmailFilter** ("User Email") — `where('id', $value)`; options `[email => id]`.
- [x] **UserNameFilter** ("User Name") — `where('user_id', $value)`; options `[name => id]`.

**Quirk to reconcile:** EmployeeEmailFilter / EmployeeNameFilter filter on `id` (not `employee_id`), and UserEmailFilter filters on `id` while UserNameFilter filters on `user_id`. Inconsistent — decide intended column per host resource during the port.

---

## 4. Metrics (7)

- [x] **AccountBalance** (Value) — `sum('balance')` over Accounts `whereIn('code', $codes)`, 2dp, currency PKR. Constructor takes `$label` + `$codes[]` (reused with different code sets). No ranges.
- [x] **ActiveEmployees** (Value) — `Employee where is_active=1 count`, suffix "active". No ranges.
- [x] **DailyCashFlow** (Trend) — per-day sum of debit (direction in) / credit (direction out) on cash accounts (codes 1100, 1150) for posted entries; joins journal_entries, groups by DATE(entry_date), prefix "PKR ". Constructor `$direction`. Ranges 14/30/60 days (default 14).
- [x] **LowStockProducts** (Value) — counts active Products where `valuation->onHand() <= reorder_level` (computed in PHP via InventoryValuationService). No ranges.
- [x] **PayrollByEmployee** (Partition) — `SUM(Payslip.net_salary)` grouped by `users.name`, scoped to active FiscalYear; joins employees+users; 2dp.
- [x] **PendingJournalEntries** (Value) — `JournalEntry where status=PENDING count`, suffix "pending". No ranges.
- [x] **UnpaidInvoices** (Value) — sale invoices (KIND_SALE) with status ISSUED/PARTIALLY_PAID; sums `outstanding()` (PHP), 2dp, currency PKR, suffix "{count} open". No ranges.

All Value metrics use `->allowZeroResult()`; currency metrics use PKR.

---

## 5. Custom Currency field

- [x] `App\Nova\Fields\Currency` extends `Laravel\Nova\Fields\Currency` (aliased `BaseCurrency`) and overrides `toMoneyInstance()`. When not using minor units and the value is numeric, it pre-rounds to the currency's fraction digits via `number_format((float)$value, $scale, '.', '')` (`$scale` from `Symfony Currencies::getFractionDigits()`) before delegating to `parent::toMoneyInstance()`. Purpose: prevent brick/money `RoundingNecessaryException` on values with excess decimals (float artifacts, 4dp decimals, computed sums). No display formatting beyond safe rounding.

**Migration:** maps to Filament `TextColumn::money('PKR')` / `TextInput` numeric — but replicate the pre-rounding to avoid rounding exceptions on computed/summed values.

---

## 6. Main Dashboard

`App\Nova\Dashboards\Main::cards()` (each card gated by a permission via `$request->user()?->can(...)`):

- [x] ActiveEmployees — perm `EmployeeView`
- [x] AccountBalance("Cash & Bank", ['1100','1150']) — `AccountView`
- [x] AccountBalance("Accounts Receivable", ['1250']) — `AccountView`
- [x] AccountBalance("Accounts Payable", ['2400']) — `AccountView`
- [x] AccountBalance("Inventory Value", ['1300']) — `AccountView`
- [x] DailyCashFlow('in') — width 1/2, `AccountView`
- [x] DailyCashFlow('out') — width 1/2, `AccountView`
- [x] PayrollByEmployee — width 1/2, `PayslipCreate`
- [x] PendingJournalEntries — `JournalEntryApprove`
- [x] UnpaidInvoices — `InvoiceView`
- [x] LowStockProducts — `ProductView`

`AccountBalance` is instantiated 4× (different label/codes); `DailyCashFlow` 2× (in/out).
