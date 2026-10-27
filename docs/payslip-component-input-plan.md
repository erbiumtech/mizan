# Retiring the eleven payslip columns

**Created:** 2026-10-27
**Status:** Phase 1 (the override map) built 2026-10-27. Phases 2 to 5 are designed below and not started.
**Source:** `docs/akaunting-gap-plan.md` item 9, "Not done: the eleven shipped pay components still have
their old columns beside them". The gate that plan asked for — `payroll:verify-components` — is clean on
production (all 88 payslips reconcile, 2026-10-27), which is what makes it safe to open this.

## What this is, and why it is not a refactor

`payslips` has eleven figure columns — `basic_wage`, `medical_allowance`, `device_allowance`,
`petrol_allowance`, `extra_work_hours`, `bonus`, `advances`, `meal_deduction`, `esi_health_insurance`,
`expense_reimbursement`, `withholding_tax` — and eight of them are **payroll input**, not just output. A
clerk typing a bonus types into `payslips.bonus`; `PayslipService::calculateByParams()` reads that column
back as the month's override and writes the computed figure to the same column. `payslip_components` rows
are a **projection written after the calculation** by `PayComponentRecorder`, so today the column is the
truth and the component row is the copy.

Retiring the columns means inverting that: the typed input moves onto a per-payslip store that the
calculation reads **before** it computes, the component rows stay the output, and the columns become
derived and then disappear. The hazard is the ordering — the recorder writes component rows on `saved`,
so an input that lived on the same rows would be both the input to a recalculation and its output, a
feedback loop. Every phase below keeps the input store and the output projection separate, keeps
`payroll:verify-components` green, and asserts that no existing payslip's net pay moves.

## The eight input overrides

`bonus`, `extra_work_hours`, `device_allowance`, `petrol_allowance`, `advances`, `meal_deduction`,
`esi_health_insurance`, `expense_reimbursement`. Each is "use the typed amount if one was given this
month, otherwise the employee's standing setting" — except `advances` (falls back to the advance ledger)
and `expense_reimbursement` (falls back to approved claims). `basic_wage` and `medical_allowance` are read
straight from the setting and are not overridable per month; `withholding_tax` and the totals are computed,
never typed.

## Phases

### Phase 1 — The override map (built 2026-10-27)

`calculateByParams()` took the eight overrides as eight positional parameters, which hardcodes the set. It
now takes one `array $overrides` keyed by component key (`['bonus' => 5000, …]`); the three call sites —
the form's derived-value calc, the form's recalculation, and the model's on-save recalculation — build the
same map from the same values. **No value changes**, and the override set is now a map a later phase can
fill from somewhere other than columns. `PayslipCalculationSeamTest` pins that the map and the old
positional form produce identical figures.

### Phase 2 — A per-payslip input store, dual-read (not started)

A `payslip_component_inputs` table (`payslip_id`, `pay_component_id`, `amount`), holding the typed override
for a month. `calculateByParams()` reads an override from the store when a row exists, otherwise from the
map it is passed (which still comes from the columns). The store starts empty, so results are identical and
the gate stays green. The recorder is untouched — it still writes the output projection. Reversible: drop
the table and the read falls back entirely to columns.

### Phase 3 — Backfill, and the form writes the store (not started)

Backfill the store from the existing column values for every editable payslip, behind
`payroll:verify-components` run before and after. The payslip form writes the typed overrides to the store
instead of to the columns. The store is now the input source; the columns are still written as output for
the readers that have not moved yet. **This is the phase where net pay could move if the backfill is
wrong** — guarded by a per-payslip before/after equality check over the whole history, not just the gate.

### Phase 4 — Components are the source, columns are derived output (not started)

`calculateByParams()` sources every override from the store; the columns are written as computed output
only, for the PDF, the reports and the billing statement until each is moved to read components. The form
no longer edits columns. The eight input columns are now write-only copies.

### Phase 5 — Drop the columns (not started)

Once every reader (payslip PDF, `PayslipService::componentTotals`, the payroll register, the billing
statement, the ledger posting) reads components rather than columns, drop the eight input columns, then the
computed ones, one migration per group, `payroll:verify-components` green at each step.

## Order and stop conditions

Phases 1 and 2 are additive and safe. Phase 3 is the hinge — do not start it on a company whose
`payroll:verify-components` is not clean, and run it inside a window with a fresh backup. Phases 4 and 5
only begin once nothing but the output projection reads a column. Any phase can ship on its own; the
columns stay until Phase 5, so a half-finished migration is a working system, not a broken one.
