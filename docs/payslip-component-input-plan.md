# Retiring the eleven payslip columns

**Created:** 2026-10-27
**Status:** Phases 1 (the override map), 2 (the input store, dual-read) and 3 (the store becomes the input, backfilled) built and deployed 2026-10-27; the production company is backfilled (81 override rows, no figure moved). Phase 4 is designed below — its one outward-facing piece, the form writing the store, is left for a session with the admin UI in view. Phase 5 is designed and not started.
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

### Phase 2 — A per-payslip input store, dual-read (built 2026-10-27)

A `payslip_component_inputs` table (`payslip_id`, `pay_component_id`, `amount`), holding the typed override
for a month. `calculateByParams()` reads an override from the store when a row exists, otherwise from the
map it is passed (which still comes from the columns). The store starts empty, so results are identical and
the gate stays green. The recorder is untouched — it still writes the output projection. Reversible: drop
the table and the read falls back entirely to columns.

### Phase 3 — The store becomes the input, backfilled (built 2026-10-27)

The save hook now moves the typed overrides onto the store. `PayslipService::syncInputStore()` runs on
every payslip save — before the figures are computed on an update, in the `created` hook on an insert — and
rebuilds the store from scratch: it clears the rows, computes the pure fallback (no overrides, empty store),
and keeps a row only where the typed figure is one no setting, ledger or claim would have produced. So a
genuine override moves to the store and keeps winning; a figure that merely echoes a fallback is left off and
keeps following it. The order matters and is the subtle part — the sync happens **before** the compute reads
the store, or a row a previous save left would win over the figure the clerk just changed.

Putting the sync in the model hook rather than rewiring the Filament form covers the form, the API and the
monthly bulk run in one place — every writer of a payslip. The columns are still written as output, so every
reader that has not moved yet is unaffected.

The existing payslips are backfilled by `php artisan payroll:backfill-component-inputs --tenant=N --apply`.
**The store must not change what the calculation produces, and it proves that before it commits:** for each
payslip it syncs the store, then recomputes twice — once with the store ignored, once with it read — and the
two must agree to the cent. That is the exact invariant: moving the input onto the store changes no figure.
Any disagreement rolls the whole tenant back. Without `--apply` it is a dry run that writes nothing.
Reversible either way: truncate `payslip_component_inputs` and every read falls back to the columns.

It deliberately does **not** gate on the stored column matching a fresh recompute, because those legitimately
differ for a reason the store neither causes nor cures. The withholding tax is an annual figure split across
the months, so a month issued before the year was complete carries a split a recompute today would
redistribute — the same annual tax, a different month. On the production company 17 of 88 payslips were in
that state, all early months, drifting by 75 to 6,500 rupees of tax timing; earnings, allowances and every
override were identical. The backfill writes input rows only and never re-saves a column, so it leaves that
split exactly as issued and reports the count as a note. Re-saving those months would restate the split,
which is a separate decision about settled payroll, not part of this phase.

### Phase 4 — The columns stop being read as input (designed 2026-10-27, not started)

The goal: nothing reads an input column to *drive* the calculation. After it, the eight input columns are
written as output and read by no one but the output consumers (PDF, reports, billing, ledger), which Phase 5
then moves off them before dropping them. Tracing it end to end found three things worth writing down before
touching code.

**The form must write the store, and that is the outward-facing part.** Today the editable fields are
`TextInput::make('device_allowance')` and the rest, bound to the payslip columns; the clerk's figure lands in
the column and the save hook reads it back as the typed input. For the column to stop being an input, the
field's value has to go to `payslip_component_inputs` instead. The low-risk shape is to keep each field
showing the computed column (so the screen is unchanged) but mark it `dehydrated(false)` and route the typed
value to the store from the page's save lifecycle, before the model computes — the store write that the Phase
3 hook does today, moved ahead of the column. It is drivable from `Livewire::test` on `EditPayslip` /
`CreatePayslip` (the way `PayslipOverrideWarningTest` already drives the form), but it changes the clerk's
primary payroll screen, so it is the one piece to do with a person able to look at the real admin UI — the
failure mode is a silently dropped override, which no green test fully rules out.

**The create path needs an id before it can source from the store.** On an update the store is synced before
the figures are computed, so the compute can read overrides from the store alone. On a create the payslip has
no id until it is inserted, so the store is empty when `creating` computes — the typed map is still needed
there. Sourcing the compute purely from the store therefore means inserting a bare row first, then syncing and
computing, which restructures the save flow; it is not a one-line change and must not regress the create path.

**Advances and expense reimbursement are not a blocker.** The worry was that a store-sourced compute would
lose the "reuse the instalment on re-save" behaviour the column gives for free. It does not:
`syncInputStore` already stores a drifted advance or claim (column ≠ the ledger/claims answer now), so a
store-sourced compute reproduces exactly what the column did. No special handling.

### Phase 5 — Migrate the readers, then drop the columns (not started)

The readers of the input columns that remain are `PayrollPostingService` (the ledger entry) and
`resources/views/pdfs/payslip.blade.php` (the PDF); the billing statement already reads components. **Move
them as a set, not one at a time** — the columns and `payslip_components` are equal today
(`payroll:verify-components` is clean) but can drift on a raw correction, and a half-migrated reader set would
have the ledger and the PDF disagree on a corrected payslip. Once nothing but the output projection reads a
column, drop the eight input columns, then the computed ones, one migration per group,
`payroll:verify-components` green at each step.

## Order and stop conditions

Phases 1 and 2 were additive and safe. Phase 3 was the hinge: the code shipped reversibly, and the backfill
per company is run with `payroll:backfill-component-inputs --apply`, which refuses to commit a tenant whose
figures would move — so it is run only on a clean `payroll:verify-components`, and still inside a window with
a fresh backup. Phases 4 and 5 only begin once nothing but the output projection reads a column. Any phase can
ship on its own; the columns stay until Phase 5, so a half-finished migration is a working system, not a
broken one.
