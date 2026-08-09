## What this is

Extra fields you define yourself, added onto records the app doesn't already
have a column for — Contacts, Employees, Invoices, Products, Beneficiaries,
and Fixed Assets today. Once defined, the field shows up on that record's own
form automatically.

## Creating one

Click **New** and pick what it **Applies to** (which of the six models
above). Give it a **Name** (what people see) and a **Code** — the machine key
the value is stored under; it's auto-suggested from the name but you can
override it. Avoid changing the code once real data exists under it, since
that's what ties old values to the field.

Pick a **Type** — Text, Textarea, Number, Date, or Select — and the form
adjusts to it:

- **Select** needs its **Options** typed in one at a time.
- **Text/Textarea/Number** can take a **Min**/**Max** (length or value).
- **Text/Textarea** can also take a **Regex pattern** to validate against.
- Most types can show a **Placeholder** inside the empty input.

**Help text** appears alongside the field wherever it's rendered. **Sort**
controls the order fields appear in relative to each other. Toggle
**Required** to enforce it, and **Active** to hide the field from forms
without deleting its definition (or its already-saved values).

## Roles and permissions

Administrator only — there's no separate `CustomField*` permission to hand to
another role. This is deliberate: a custom field changes the shape of other
people's forms, which is treated as an administrative act rather than
day-to-day data entry. This page is also unavailable if custom fields are
disabled for the installation (`custom_fields.enabled` in config), independent
of any module.
