# What Operentra has that we do not

**Created:** 2026-10-29
**Status:** inventory only — nothing here is decided. `docs/saas-launch-plan.md` is where gaps get
designed and scheduled.

Read against Operentra's own product, not its feature page: every admin and portal screen of a
trial workspace was crawled signed in (186 routes from the app's router), then filled with six
employees, a month of attendance, leave, a loan, an expense claim, an appraisal cycle, a
separation and a payroll, and crawled again (209 pages, 21 of them record pages). That produced
199 features, each checked against this codebase's code — models, Filament resources, services,
migrations, tests — not its docs. Phases 0–10 of the launch plan were in when this was written; the Devices
module (Phase 11) and custom domains (Phase 12) landed on 2026-10-29, so the rows below that say
*uncommitted* are now committed — the inventory itself was not re-crawled.

**The headline: the names are here, the depth is not.** Of 199 features, 38 are here as fully as
Operentra has them, 107 exist in a narrower form, and 54 do not exist. The plan's §1 counted 76 of
their 101 *advertised* features as fully here; that was a comparison of names, and it holds. This
one compares implementations, and "partial" mostly means the thing exists with one shape where
theirs has several — one expense line instead of many, one approval step instead of two, an option
list where they have an entity.

Verdicts are strict. **Have:** works end to end. **Partial:** exists but narrower; the note says
what is missing. **Missing:** no code. Equivalent features under other names count (work patterns
for shifts, advances for loans, work locations for branches, vacancies for job openings).

---

## 1. Counts

| Area | Have | Partial | Missing |
|---|---|---|---|
| Organization & company settings | 3 | 9 | 3 |
| Employees | 2 | 9 | 10 |
| Contracts | 1 | 3 | 2 |
| Onboarding, probation, offboarding | 0 | 4 | 2 |
| Attendance | 1 | 11 | 2 |
| Field visits | 0 | 3 | 1 |
| Leave | 3 | 4 | 3 |
| Holidays | 0 | 3 | 1 |
| Payroll | 1 | 13 | 3 |
| Cheques | 2 | 1 | 0 |
| Tax | 1 | 1 | 1 |
| Loans & advances | 1 | 2 | 2 |
| Expenses | 1 | 3 | 0 |
| Announcements | 0 | 1 | 0 |
| HR letters | 1 | 2 | 2 |
| Brand Studio | 0 | 0 | 4 |
| Appraisals | 1 | 2 | 3 |
| Assets | 1 | 1 | 0 |
| Recruitment | 4 | 1 | 0 |
| Performance | 2 | 4 | 4 |
| Reports | 2 | 5 | 0 |
| Access control & audit | 1 | 5 | 2 |
| Settings, notifications, integrations, API | 1 | 5 | 3 |
| Employee portal | 7 | 11 | 3 |
| Commercial, platform, languages | 2 | 4 | 3 |
| **Total** | **38** | **107** | **54** |

## 2. The 54 missing, by theme

- **The employee record.** Statuses beyond active/inactive, bulk activation, an emergency contact
  with a relation, father's name / NTN / nationality / marital status, an email language, a photo
  and its approval queue, a bulk CSV import, probation dates with a list and a confirm action,
  rehire.
- **Verification.** Postal address codes, SMS OTP for the mobile and emergency contact, a
  mandatory-document list and its compliance report, the settings for all of it, and the portal
  gate that enforces it.
- **Organization.** Pay grades (deferred in the plan's §13), the statutory-coverage panel,
  per-employee subject-access export and erasure.
- **Contracts.** Templates in several languages; draft → active with the employee's electronic
  acceptance.
- **Lifecycle.** Onboarding tasks that run, link out or close themselves from the records; an
  account grace period after leaving.
- **Attendance and leave.** Rest-day swaps, missing-punch reminders, field-staff enrolment and GPS
  settings, leave groups, a leave calendar, encashment outside separation, a holiday CSV import.
- **Money.** Salary-history import, a provident-fund ledger per employee, payslip templates, tax
  adjustments, loan types, employees applying for a loan.
- **Letters and Brand Studio.** Letter templates in several languages, letters issued by events
  (an increment letter), ID cards, business-card orders, email signatures, a brand kit.
- **Appraisals and performance.** Templates with weighted competencies, rating-scale settings,
  changing the reviewer, weekly sales reports, scorecard profiles, the GitHub and WHMCS feeds and
  the identity matching behind them.
- **Platform.** Resetting a user's 2FA, an SMS log, on-demand admin runs (evaluate badges, open
  the leave year), outgoing webhooks, coupon and AppSumo codes, an affiliate programme, a second
  language.

## 3. The partials that matter most

Ranked by how often a buyer meets them in the first month:

1. **Payroll run lifecycle** — open → locked → reopen, against their review → approved → locked →
   paid with a pre-flight check and staleness warnings.
2. **Salary structures** — a dated package of itemised amounts per employee, with no structure
   template, no split from a gross, no formulas, no EOBI/PF/overtime/gratuity flags on components.
3. **Expenses** — one amount and one receipt per claim, saved as submitted, approved in one step
   by any approver rather than the manager and then HR.
4. **Advances** — HR records them; no types, limits, interest or employee application.
5. **Departments and designations** — option lists, so no parent, code, head, level or pay grade.
6. **Employee directory** — no department or status filter, no grid.
7. **Shifts** — work patterns with no break, per-shift grace, attendance mode or assignment screen.
8. **Appraisals** — one overall 1–5 rating, no employee self-review.
9. **Interviews** — a model and a stage, no screen to schedule them or record feedback.
10. **Email templates** — 16 plain-text overrides in English against their ~70 in five languages.
11. **API** — about 27 routes, mostly self-service, no customer key screen, scopes or usage.

## 4. Where this side is ahead

Unchanged from the plan's §1: the double-entry ledger and everything on it, invoicing, CRM,
support, inventory, timesheets, the construction modules, the FBR, EOBI and bank files, the
payslip's acknowledge/reject thread and WhatsApp delivery, leave approval chains, comp-off. Two
places where their arithmetic went wrong on the same data are right here: lateness never docks pay
by design, and the sandwich rule counts only weekends *between* leave days, so a Thursday–Friday
leave is two days (`LeaveDayGenerator`). Self-serve signup, the merit ledger, recurring tasks,
daily-report verdicts, cheque printing, field visits, QR verification and the careers page all
landed in Phases 1–10.

---

## 5. Feature by feature

### Organization & company settings

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Company profile (legal name, reg no., NTN, STRN, address, date format) | Partial | `app/Modules/Core/Filament/Pages/CompanySettings.php` | Letterhead name, reg no., NTN, address; no STRN or date format |
| Branches (code, address, city, phone, map URL, head office) | Partial | `app/Modules/Core/Filament/Resources/WorkLocations/WorkLocationResource.php` | Name/code/address/city plus geofence; no phone, map URL, head-office flag |
| Departments (parent, code, head, cost center) | Partial | `app/Modules/Employees/module.php` | Option list of names, used as a ledger dimension |
| Pay grades linked from designations | Missing | — | Deferred, plan §13 |
| Designations (level, pay grade, JD, report-exempt) | Partial | `app/Modules/Employees/module.php` | Option list of titles |
| Employment types with perks | Partial | `app/Modules/Employees/module.php` | Option list; no residential/accommodation/meals flags |
| Fiscal years (several, one current, open/closed) | Have | `app/Modules/Core/Filament/Resources/FiscalYears/Tables/FiscalYearsTable.php` | Close and reopen |
| Company bank accounts with purpose | Have | `app/Modules/Accounting/Filament/Resources/CompanyBankAccounts/Schemas/CompanyBankAccountForm.php` | Purpose is a transaction type, one default each |
| Banks directory (IMD, SWIFT, IBAN prefix, country) | Partial | `app/Modules/Core/Models/Bank.php` | IMD, name, short code only |
| Employee code prefix and digits setting | Partial | `app/Modules/Employees/Models/Employee.php` | Fixed `EMP-` + 4 digits; prefix only as a CLI option |
| Logo, favicon, app logo, seal/stamp | Partial | `app/Modules/Core/Filament/Pages/CompanySettings.php` | Logo and signature/stamp; no favicon upload |
| Statutory-coverage panel | Missing | — | |
| Company data export | Have | `app/Support/TenantExport.php` | SQL dump plus files in a ZIP, not JSON |
| Customer "Back up now", 14 snapshots | Partial | `app/Backup/TenantBackup.php` | Server-side `backup:tenants` only |
| Subject-access export and erasure per employee | Missing | — | |

### Employees

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Directory: list + grid, filters, sorts | Partial | `app/Modules/Employees/Filament/Resources/Employees/Tables/EmployeesTable.php` | List only; no department or status filter |
| Statuses (new, active, on leave, suspended, terminated…) | Partial | `app/Modules/Employees/Filament/Resources/Employees/Schemas/EmployeeForm.php` | `is_active` plus `left_on` |
| Bulk activate (leave, portal account) | Missing | — | |
| Father's name, CNIC, NTN, nationality, DOB, gender, marital, emails, phone + country code | Partial | `app/Modules/Employees/Filament/Resources/Employees/Schemas/EmployeeForm.php` | No father, NTN, nationality, marital status, country code |
| Emergency contact with relation | Missing | — | `secondary_phone` only |
| Addresses (current, permanent) | Partial | `app/Modules/Employees/Models/Employee.php` | Two free-text lines |
| Employee bank account | Have | `app/Modules/Employees/Filament/Resources/Employees/Schemas/EmployeeForm.php` | One per employee |
| Manager, shift, leave group, probation end, confirmation | Partial | `app/Modules/Employees/Filament/Resources/Employees/Schemas/EmployeeForm.php` | Manager only; shift has no assignment screen |
| Email language per employee | Missing | — | No translations exist |
| Photo + photo-change approval | Missing | — | |
| Self-service edits approved by HR | Have | `app/Modules/Employees/Models/EmployeeChangeRequest.php` | |
| Bulk CSV import (+ salaries) | Missing | `app/Support/CsvImporters.php` | No employee importer registered |
| Documents: required types, formats, verify/reject queue | Partial | `app/Modules/Lifecycle/Filament/Resources/EmployeeDocuments/EmployeeDocumentResource.php` | Fixed kinds and expiry; `verified_at` unused |
| Document compliance report | Missing | `app/Modules/Lifecycle/Filament/Pages/DocumentsExpiring.php` | Expiry report only |
| Address verification by postal code | Missing | — | |
| Mobile and emergency-contact OTP | Missing | — | |
| Transfers as dated records | Partial | `app/Modules/Employees/Filament/Resources/Employees/RelationManagers/JobHistoryRelationManager.php` | Department changes logged; location changes not |
| Promotions as records | Partial | `app/Modules/Employees/Models/EmployeeJobHistory.php` | Designation change in job history; no promotion entity |
| Probation list and confirm | Missing | — | Probation is an employment-type value |
| Rehire | Missing | — | |
| Shift assignment history | Partial | `app/Modules/Attendance/Services/WorkPatternResolver.php` | Dated table read by the resolver; no UI |

### Contracts

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Contracts (types, number, dates, gross, probation) | Partial | `app/Modules/Lifecycle/Models/EmployeeDocument.php` | A `contract` document kind, not an entity |
| Templates, several languages | Missing | — | |
| Expiring contracts | Have | `app/Modules/Lifecycle/Support/LifecycleReports.php` | Within the documents-expiring report |
| Draft → active, employee e-accepts | Missing | — | |
| Witnesses, signed copy, IP disclosure | Partial | `app/Modules/Lifecycle/Filament/Resources/EmployeeDocuments/EmployeeDocumentResource.php` | Signed copy as a document only |
| Preview / PDF | Partial | `app/Modules/Employees/Services/AppointmentLetter.php` | Appointment letter only |

### Onboarding, probation, offboarding

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Pipeline per hire, with stages | Partial | `app/Modules/Lifecycle/Listeners/StartOnboardingForNewEmployee.php` | Only on a Recruitment hire with one template; no stages |
| Run / link / auto-check tasks | Missing | `app/Modules/Lifecycle/Services/ChecklistService.php` | Plain titles; no tick UI |
| Separations by type | Partial | `database/migrations/tenant/2026_08_12_120000_add_leaving_date_to_employees.php` | `left_on`; `leaving_reason` has no UI |
| Clearance: auto + manual items, waive | Partial | `app/Modules/Lifecycle/Filament/Resources/ChecklistTemplates/ChecklistTemplateResource.php` | Manual exit templates only |
| Settlement preview | Partial | `app/Modules/Lifecycle/Services/FinalSettlementBuilder.php` | Service, encashment ÷26, gratuity, advances, assets; no PF |
| Account grace period after leaving | Missing | — | |

### Attendance

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Daily sheet + CSV/print | Partial | `app/Modules/Attendance/Filament/Resources/AttendanceDays/Tables/AttendanceDaysTable.php` | CSV/PDF on the monthly register only |
| Mark one / bulk "all present" | Partial | `app/Modules/Attendance/Filament/Resources/AttendanceDays/Pages/ListAttendanceDays.php` | Record a day; "open a month" sets not-marked |
| CSV upload with template | Partial | `app/Modules/Attendance/Services/AttendanceImport.php` | Dry run and ZKTeco preset; no template download |
| Raw punch log | Partial | `app/Modules/Devices/Models/DevicePunch.php` | Uncommitted; stored, no screen |
| Regularization requests | Have | `app/Modules/Attendance/Filament/Resources/AttendanceRegularizations/AttendanceRegularizationResource.php` | |
| Overtime requests and settings | Partial | `app/Modules/Attendance/Services/AttendanceRecorder.php` | Computed from the pattern; no request/approval |
| Shifts (break, grace, mode, salary flag…) | Partial | `app/Modules/Attendance/Filament/Resources/WorkPatterns/WorkPatternResource.php` | Per-weekday hours and offs only |
| Rest-day swaps | Missing | `app/Modules/Attendance/Services/CompensatoryOffAccrual.php` | Comp-off is the nearest thing |
| Grace, checkout grace, N lates = absence | Partial | `config/attendance.php` | One global grace; lates never dock pay, by design |
| Missing-punch reminders | Missing | — | |
| Biometric devices (users, templates, backups, commands) | Partial | `app/Modules/Devices/Filament/Settings/DevicesSettingsSection.php` | Uncommitted; ZKTeco push registration and silence alert |
| Punch → employee mapping, unmapped list | Partial | `app/Modules/Devices/Services/DevicePunchFolder.php` | Uncommitted; device PIN mapping, unmapped list in settings |
| Geo attendance (geofence, GPS punch, accuracy, attempts log) | Partial | `app/Modules/Core/Models/WorkLocation.php` | Geofence flag/refuse; no accuracy limit or attempts log |
| Capture-method choice | Partial | `app/Modules/Attendance/Models/AttendanceDay.php` | All sources coexist; no per-company choice |

### Field visits

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Check-in/out with GPS verdict, map, CSV | Partial | `app/Modules/Crm/Filament/Resources/FieldVisits/FieldVisitResource.php` | Coordinates and a map link; no verdict or map view |
| Client directory (pin, radius, owner) | Partial | `app/Modules/Crm/Models/FieldVisit.php` | Visits target CRM leads, deals, customers |
| Staff enrolment, GPS settings, photo, quick-add | Missing | `app/Modules/Crm/Filament/Settings/FieldVisitsSettingsSection.php` | Only the confirmation toggle |
| Client feedback after check-out | Partial | `app/Modules/Crm/Http/Controllers/FieldVisitConfirmationController.php` | Signed confirmation link; no rating or SMS |

### Leave

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Types: code, colour, paid, pay % | Partial | `app/Modules/Leave/Filament/Resources/LeaveTypes/Schemas/LeaveTypeForm.php` | No colour or pay percent |
| Policies (cyclic, max consecutive, gender, service, employment type, doc after N) | Partial | `app/Modules/Leave/Models/LeaveType.php` | Accrual, carry-forward, pro-rata, document flag only |
| Leave groups | Missing | — | Every type applies to everyone |
| Pakistan statutory set seeded | Partial | `database/seeders/LeaveTypeSeeder.php` | No Umrah or Marriage; no Hajj service rule |
| Allocation run for a year | Have | `app/Modules/Leave/Console/Commands/OpenLeaveYear.php` | Calendar, fiscal or anniversary year |
| Balances per employee | Have | `app/Modules/Leave/Filament/Resources/LeaveEntitlements/LeaveEntitlementResource.php` | |
| Approval (manager / HR by type), pending screen | Partial | `app/Modules/Leave/Services/LeaveApprovalChain.php` | Chain is company-wide, not per type |
| Leave calendar | Missing | — | |
| Encashment outside separation | Missing | `app/Modules/Lifecycle/Services/FinalSettlementBuilder.php` | Settlement only |
| Sandwich rule | Have | `app/Modules/Leave/Services/LeaveDayGenerator.php` | Weekends between leave days only |

### Holidays

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Calendar view, by year | Partial | `app/Modules/Core/Filament/Resources/Holidays/Tables/HolidaysTable.php` | Sorted list; no grid or year filter |
| Types, branch-specific, recurring | Partial | `app/Modules/Core/Models/Holiday.php` | Per location; recurring flag has no rollover; no type |
| CSV import | Missing | `app/Support/CsvImporters.php` | |
| Public-holiday suggestions | Partial | `app/Modules/Core/Services/PakistanPublicHolidays.php` | Built-in 2026–27 list; no sync or accept/dismiss |

### Payroll

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Process with pre-flight | Partial | `app/Modules/Payroll/Filament/Resources/Payslips/Pages/ListPayslips.php` | Raises payslips; no checks before the run |
| Run lifecycle, send back, reprocess, staleness | Partial | `app/Modules/Payroll/Models/PayrollRun.php` | Open → locked → reopen |
| Structures and component flags | Partial | `app/Modules/Payroll/Filament/Resources/PayComponents/Schemas/PayComponentForm.php` | No structures, formulas, exemptions, EOBI/PF/OT/gratuity flags |
| Assign salary (structure + gross + from) | Partial | `app/Modules/Employees/Filament/Resources/EmployeeSettings/Schemas/EmployeeSettingForm.php` | Dated itemised package; no split from gross |
| Salary-history import | Missing | `app/Support/CsvImporters.php` | |
| Increments and decrements with letter | Partial | `app/Modules/Performance/Services/ReviewCycleService.php` | Increment from an appraisal only; no decrement, no letter |
| Bonuses | Partial | `app/Modules/Payroll/Filament/Resources/Payslips/Schemas/PayslipForm.php` | A figure on the payslip; no records or types |
| Adjustments | Partial | `app/Modules/Payroll/Services/PayslipService.php` | Overrides of fixed components only |
| Absence, unpaid leave, late deductions | Partial | `app/Modules/Payroll/Services/AttendanceFigures.php` | Pro-rating off by default; lates never deducted |
| PF ledger per employee | Missing | `app/Modules/Payroll/Services/StatutoryContributions.php` | A computed component only |
| Contribution funds | Partial | `config/accounting.php` | Payable accounts; no remittance or profit share |
| Slips: email, bulk email, bulk download | Partial | `app/Modules/Payroll/Filament/Resources/Payslips/Tables/PayslipsTable.php` | Email, WhatsApp, bulk send; no bulk download |
| Slip acknowledgement with reminders | Partial | `app/Modules/Payroll/Models/Payslip.php` | Accept/reject thread; no reminders |
| Slip templates, languages | Missing | `resources/views/pdfs/payslip.blade.php` | |
| Payroll settings (rounding, pay day, cheque validity) | Partial | `app/Modules/Core/Filament/Pages/CompanySettings.php` | Pro-rating, divisor, overtime only |
| Bank-file designer | Partial | `app/Support/Banking/IPaymentsFileWriter.php` | Fixed Standard Chartered format |
| Run CSV export and print | Have | `app/Modules/Payroll/Filament/Pages/PayrollRegister.php` | |

### Cheques, tax, loans, expenses

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Cheque register | Have | `app/Modules/Accounting/Filament/Pages/ChequeRegister.php` | With void, reprint, clearing |
| Print cheques for a run | Partial | `app/Modules/Accounting/Filament/Resources/Payments/Tables/PaymentsTable.php` | One salary payment at a time |
| Cheque layouts | Have | `app/Modules/Accounting/Filament/Resources/ChequeLayouts/ChequeLayoutResource.php` | |
| Tax slabs per year | Have | `app/Modules/Payroll/Filament/Resources/SalarySlabs/SalarySlabResource.php` | |
| Tax certificates, templates | Partial | `app/Modules/Payroll/Services/WithholdingCertificate.php` | Works; fixed layout |
| Tax adjustments | Missing | `app/Modules/Payroll/Filament/Resources/AnnualTaxes/Schemas/AnnualTaxForm.php` | Computed, read-only |
| Loan types | Missing | `app/Modules/Advances/Models/Advance.php` | |
| Employee applies; approve/reject | Missing | `app/Modules/Advances/module.php` | HR records advances |
| Schedule, interest, pause/resume | Partial | `app/Modules/Advances/Filament/Resources/Advances/Schemas/AdvanceForm.php` | Flat instalment, skipped months |
| Deductions in payroll | Have | `app/Modules/Advances/Services/AdvanceService.php` | |
| Loan reports | Partial | `app/Modules/Advances/Support/AdvanceReports.php` | Outstanding only |
| Expense categories | Partial | `app/Modules/Expenses/Filament/Resources/ExpenseClaims/Schemas/ExpenseClaimForm.php` | Accounting transaction types |
| Multi-line claims, receipts, draft | Partial | `app/Modules/Expenses/Models/ExpenseClaim.php` | One amount, one receipt, no draft |
| Manager then HR approval | Partial | `app/Modules/Expenses/Policies/ExpenseClaimPolicy.php` | One step |
| Reimbursed through payroll | Have | `app/Modules/Expenses/Services/ExpenseClaimService.php` | Or by bank payment |

### Announcements, letters, Brand Studio, assets

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Announcements | Partial | `app/Modules/Employees/Models/Announcement.php` | No category, priority, role/person target, scheduled publish, archive |
| Letter types | Partial | `app/Modules/Employees/Filament/Resources/Employees/Pages/ViewEmployee.php` | Offer, appointment, experience, income, custom |
| Letter templates, languages | Missing | `resources/views/pdfs/custom-letter.blade.php` | Fixed English views |
| Letter requests from employees | Partial | `app/Modules/Employees/Filament/Resources/Employees/Pages/ViewEmployee.php` | Self-download; no request queue |
| QR verification | Have | `app/Support/DocumentVerification.php` | Not on custom letters |
| Letters issued by events | Missing | `app/Modules/Employees/Models/EmployeeChangeRequest.php` | |
| ID cards | Missing | — | |
| Business-card orders | Missing | — | |
| Email signatures | Missing | — | |
| Brand kit | Missing | `app/Modules/Core/Filament/Pages/CompanySettings.php` | Logo and stamp only |
| Asset register fields | Partial | `app/Modules/Lifecycle/Models/IssuedAsset.php` | Fixed kinds; no tag, vendor, warranty |
| Issue / return, history | Have | `app/Modules/Lifecycle/Filament/Resources/IssuedAssets/IssuedAssetResource.php` | |

### Appraisals, recruitment, performance

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Cycles, roll out, close | Have | `app/Modules/Performance/Filament/Resources/ReviewCycles/ReviewCycleResource.php` | Free dates, no cadence |
| Templates with weighted competencies | Missing | `app/Modules/Performance/Models/Review.php` | |
| Self and manager review per competency | Partial | `app/Modules/Performance/Filament/Resources/Reviews/ReviewResource.php` | One overall rating; no employee self-review |
| Finalize with increment and promotion | Partial | `app/Modules/Performance/Services/ReviewCycleService.php` | Percentage increment only |
| Rating-scale settings | Missing | `config/performance.php` | Fixed 1–5 |
| Change reviewer | Missing | `app/Modules/Performance/Services/ReviewCycleService.php` | |
| Job openings | Have | `app/Modules/Recruitment/Filament/Resources/Vacancies/VacancyResource.php` | |
| Careers page | Have | `app/Modules/Recruitment/Http/Controllers/CareersController.php` | |
| Candidates and stages | Have | `app/Modules/Recruitment/Filament/Resources/Applications/ApplicationResource.php` | |
| Interviews and feedback | Partial | `app/Modules/Recruitment/Models/Interview.php` | Model only |
| Extend offer | Have | `app/Modules/Recruitment/Filament/Resources/Applications/ApplicationResource.php` | |
| Scoreboard composite | Partial | `app/Modules/Performance/Support/PerformanceReports.php` | Net points; no quality/volume |
| Merit ledger | Have | `app/Modules/Performance/Services/MeritLedger.php` | |
| Tasks and recurring tasks | Partial | `app/Modules/Performance/Services/TaskRoller.php` | Recurring only; no reminders |
| Daily reports, AI-graded | Have | `app/Modules/Performance/Services/ReportVerdicts.php` | |
| Weekly sales reports | Missing | — | |
| Increment recommendations from scores | Partial | `app/Modules/Performance/Services/ReviewCycleService.php` | From the appraisal rating |
| Scorecard profiles | Missing | `config/performance.php` | |
| GitHub and WHMCS feeds | Missing | — | |
| Identity matching | Missing | — | |
| Badges, evaluated in bulk | Partial | `app/Modules/Performance/Services/Badges.php` | Hand-awarded |

### Reports, access, settings

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Employee reports | Partial | `app/Modules/Employees/Filament/Pages/HeadcountMovement.php` | No anniversaries; demographics by gender only |
| Attendance reports | Partial | `app/Modules/Attendance/Support/AttendanceReports.php` | One monthly register |
| Leave reports | Partial | `app/Modules/Leave/Reporting/LeaveDayDataset.php` | Builder dataset and liability report |
| Payroll reports | Have | `app/Modules/Payroll/PayrollServiceProvider.php` | |
| Tax reports | Partial | `app/Modules/Payroll/Services/WithholdingTaxSummary.php` | No slab analysis, though the catalogue says so |
| Loan and appraisal reports | Partial | `app/Modules/Performance/Support/PerformanceReports.php` | No ratings distribution |
| CSV on every report | Have | `app/Support/Reporting/ExportsTheOpenReport.php` | CSV and PDF |
| Users screen | Partial | `app/Modules/Core/Filament/Resources/Users/Tables/UsersTable.php` | No last login or resend |
| Roles: locked system roles, duplicate | Partial | `app/Modules/Core/Filament/Resources/Roles/Tables/RolesTable.php` | Editable; no duplicate |
| Impersonation | Have | `app/Support/Impersonation.php` | |
| Reset a user's 2FA | Missing | — | |
| Login history | Partial | `app/Modules/Core/Listeners/LogAuthenticationEvent.php` | Operator panel only |
| Activity log filters | Partial | `app/Modules/Core/Filament/Resources/ActivityLogs/Tables/ActivityLogsTable.php` | No action/entity/date filters |
| Email send log | Partial | `app/Modules/Core/Filament/Pages/ReportDeliveries.php` | Scheduled reports only |
| SMS send log | Missing | `app/Support/WhatsApp/WhatsAppSender.php` | WhatsApp, logged to the app log |
| SMS providers | Partial | `config/whatsapp.php` | WhatsApp only |
| Email templates | Partial | `app/Modules/Core/Models/EmailTemplate.php` | 16, English, plain text |
| Notification settings per category | Partial | `app/Modules/Core/Filament/Pages/CompanySettings.php` | One company toggle |
| Notification centre | Have | `app/Providers/Filament/AdminPanelProvider.php` | |
| On-demand admin runs | Missing | `app/Modules/Leave/Console/Commands/OpenLeaveYear.php` | |
| Customer API keys | Partial | `app/Http/Controllers/Api/AuthController.php` | Login tokens, no UI or scopes |
| Outgoing webhooks | Missing | `routes/api.php` | |
| Verification settings | Missing | `app/Modules/Core/Filament/Settings/DocumentVerificationSettingsSection.php` | QR only |
| Workspace settings | Partial | `app/Modules/Core/Filament/Pages/Subscription.php` | No language or sender name |

### Employee portal

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Dashboard tiles | Partial | `app/Modules/Performance/Filament/Widgets/BadgesOverview.php` | Badges and announcements only |
| Verification gate | Missing | `app/Modules/Employees/Models/Employee.php` | |
| Attendance with OT and corrections | Partial | `app/Modules/Attendance/Filament/Resources/AttendanceRegularizations/AttendanceRegularizationResource.php` | No overtime request |
| GPS self clock-in | Have | `app/Modules/Attendance/Http/Controllers/AttendanceController.php` | |
| Leaves: balances, apply, cancel | Partial | `app/Modules/Leave/Filament/Resources/LeaveRequests/Schemas/LeaveRequestForm.php` | No per-type eligibility |
| Salary slips, acknowledge, PDF | Have | `app/Modules/Payroll/Filament/Resources/Payslips/Tables/PayslipsTable.php` | |
| Tax certificates | Have | `app/Modules/Payroll/Filament/Resources/Payslips/Pages/ListPayslips.php` | |
| Loans: apply, schedule | Partial | `app/Modules/Advances/module.php` | View only |
| Expenses with line items | Partial | `app/Modules/Expenses/Filament/Resources/ExpenseClaims/Schemas/ExpenseClaimForm.php` | One line |
| Team expense approval | Have | `app/Modules/Expenses/Policies/ExpenseClaimPolicy.php` | Scoped to the downline |
| Document upload | Partial | `app/Modules/Employees/Models/EmployeeChangeRequest.php` | NIC images only |
| My assets | Have | `app/Modules/Lifecycle/Filament/Resources/IssuedAssets/IssuedAssetResource.php` | |
| Contract review and accept | Missing | `app/Modules/Employees/Services/AppointmentLetter.php` | |
| Letters list and requests | Partial | `app/Modules/Employees/Filament/Resources/Employees/Pages/ViewEmployee.php` | Self-print only |
| Appraisals: self review, reviews to do | Partial | `app/Modules/Performance/Filament/Resources/Reviews/ReviewResource.php` | Managers only |
| Tasks, score, daily and weekly reports | Partial | `app/Modules/Performance/routes/api.php` | No weekly sales report |
| Interviews with feedback | Partial | `app/Modules/Recruitment/Models/Interview.php` | Schema only |
| ID card, business card, signature | Missing | — | |
| Field visits | Have | `app/Modules/Crm/routes/api.php` | Needs the CRM module |
| Profile, edits for approval, language | Partial | `app/Modules/Employees/Models/Employee.php` | No language preference |
| Notifications | Have | `app/Providers/Filament/AdminPanelProvider.php` | No mobile API endpoint |

### Commercial, platform, languages

| Feature | Verdict | Evidence | Note |
|---|---|---|---|
| Self-serve signup, email verification | Have | `app/Modules/Core/Http/Controllers/SignupController.php` | 14-day Growth trial |
| Plans, 10-seat minimum, monthly/yearly, change plan | Partial | `config/plans.php` | `Company::changePlan()` has no UI |
| Seats held above headcount | Partial | `app/Support/Subscriptions.php` | max(headcount, 10); no reserved seats |
| Coupons, AppSumo | Missing | — | |
| Affiliate programme | Missing | — | |
| Operator console | Partial | `app/Providers/Filament/PlatformPanelProvider.php` | Companies, users, roles, audit, Horizon, health |
| Custom domain | Have | `app/Support/CustomDomains.php` | Uncommitted; Business plan |
| Five languages | Missing | `config/app.php` | English only |
| REST API with docs | Partial | `docs/api/openapi.yaml` | ~27 routes, spec covers 15, not served |
