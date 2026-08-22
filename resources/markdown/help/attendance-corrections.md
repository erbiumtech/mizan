## What this is

"I was here that day." An employee asks for a day to be recorded correctly; somebody
else approves it.

This exists so that **Not marked** has a way out that is not an administrator editing
rows. Without one it simply accumulates, and the whole distinction between "nobody
answered" and "absent" stops being believed.

## Asking <!-- requires: AttendanceRegularizationCreate -->

Pick the day, say what it actually was, add the times if you have them, and say why it
was not recorded. The reason is required and it stays on the request after approval, so
the correction is auditable rather than a silent overwrite.

Only days in the past. This is a correction, not a plan.

## Two things that cannot be corrected <!-- requires: AttendanceRegularizationCreate -->

Both are refused outright, and neither is a setting anybody can turn off:

- **A day covered by approved leave.** Attendance and leave disagreeing about whether
  somebody was at work is the contradiction the two modules exist to prevent. Withdraw
  the leave first if the person actually worked.
- **A day inside a payroll month that has been signed off.** Once a run is locked, the
  attendance behind it is what somebody was paid on. Changing it afterwards makes the
  payslip impossible to reproduce. Raise the correction against the current month
  instead — the same rule that stops leave being recounted after approval.

A month that was locked and then deliberately *reopened* can be corrected. It is the
lock that closes the door, not the fact that it was once locked.

## Approving <!-- requires: AttendanceRegularizationApprove -->

Approving writes the day exactly as asked, marked as coming from the employee rather
than from a clerk or a device, and keeps the original request beside it.

**Nobody may approve a correction about themselves**, and unlike leave there is no
setting to waive that. A leave dead end is real — the person at the top of the tree has
nobody above them, and refusing would stop them taking leave at all. A correction to
one's own attendance approved by oneself is just an edit with extra steps, and refusing
it blocks nobody from working.

Refusing needs a reason, which the employee sees.

## Roles and permissions

**View**: `AttendanceRegularizationView` — your own and your reports'. **Ask**:
`AttendanceRegularizationCreate`, which every employee holds. **Approve or refuse**:
`AttendanceRegularizationApprove`, which they do not.
