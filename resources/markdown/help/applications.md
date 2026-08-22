## What this is

Who is where in the pipeline: applied, screening, interview, offer.

## Moving somebody along <!-- requires: ApplicantUpdate -->

The stage dropdown covers the open stages. **Hiring and rejecting are actions**, not
dropdown choices, because each has to record *why* and *when* — a dropdown would let
somebody set "rejected" with neither.

**Rejecting requires a reason.** It is kept on the record, and it is also what starts the
two-year retention clock on the applicant.

## Making an offer <!-- requires: ApplicantUpdate -->

Records the salary and the joining date. The allowances that will make up the package can
be added to the offer, and become the employee's salary components at acceptance.

## Hiring <!-- requires: OfferHire -->

**Accept and hire** creates, in one transaction:

- the **employee** record, with their designation, department and manager taken from the
  vacancy;
- their **salary package** from the offer, starting on the joining date — not on the
  fiscal year's start, because somebody joining in September has no agreed package for
  July;
- optionally a **login**. Not required: employees without one are supported, and a factory
  floor usually does not get accounts.

**All or nothing.** If any part fails, none of it happened — a company left with an
employee who has no salary package, or a package with no employee, is worse off than one
whose button showed an error.

The application keeps `employee_id` afterwards, so the trail from vacancy to payroll
survives. "Where did this employee come from" stays answerable years later.

**Hiring is its own permission**, separate from moving people through the pipeline:
creating an employee and a salary package is a bigger decision. And without the Employees
module the action is **absent** rather than broken — there is nothing yet for an accepted
offer to become.

## Roles and permissions

**View / update**: `ApplicantView`, `ApplicantUpdate`. **Hire**: `OfferHire`.
