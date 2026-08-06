![MPRs](/images/help/mprs.png)

## What an MPR is

A Monthly Performance Review: one dated record per user covering feedback,
topics & scope discussed, the module recently worked on, any request the
employee raised, the goal for next month, and what they learned this month.
Each field is a rich-text entry (formatting and file attachments included).

## Creating and editing <!-- requires: MPRCreate, MPRUpdate -->

Click **New**, pick the **User** the review is for and the **Date**, and fill
in the six fields. There's no workflow beyond save — no draft/submit/approve
states, no status field.

## Downloading a PDF

Two ways to get one, both from this list:

- **Download PDF** on a single row — generates (or reuses, if already
  generated) a one-page PDF of just that record and opens it in a new tab.
- **Generate / Download PDF** in the header — builds a **comparison** PDF of a
  chosen user's two most recent MPRs side by side (landscape), so a reviewer
  can see this month against last month at a glance. Falls back to a
  single-record PDF if that user only has one MPR on file, and refuses with a
  notification if they have none. Non-administrators only see themselves in
  the user picker here — the comparison is for your own record, not a
  colleague's.

## Who sees which records

An Administrator sees every MPR. Everyone else sees only their own plus their
reporting downline's, the same scoping Payslips and Expense Claims use — an
MPR is no more public than a performance review normally would be.

## Roles and permissions

**No seeded role — Employee, Accountant, Manager, or CEO — holds any MPR
permission by default.** `MPRView`, `MPRCreate`, `MPRUpdate`, and `MPRDelete`
exist, but only an Administrator (who holds every permission) can reach this
resource out of the box; granting it to another role means adding the
permission through Roles & Permissions. Depends on the MPR module being
enabled for the company.
