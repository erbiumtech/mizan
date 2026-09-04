<?php

namespace App\Support\Contracts;

/**
 * A record that names who should hear when it changes.
 *
 * `App\Support\RecordAudience` answers this generically for any record carrying a user
 * column — `user_id`, `created_by`, `requested_by` — which covers forty-two tables
 * without a line of per-model code. This contract is for the two cases that generic rule
 * cannot reach:
 *
 *  - **The audience is an employee, not a user.** A ticket's assignee is an
 *    `assignee_employee_id`, and shared code may not import a module's Employee model to
 *    follow it (ModuleBoundaryTest). The module can, so the model answers for itself.
 *  - **The model already tells people itself.** A payslip, a leave request and an expense
 *    claim each have their own notification saying something better than "a record
 *    changed". Returning an empty audience is how one of those says so, and reads as a
 *    decision rather than an omission.
 *
 * Return user ids rather than models where you can: the listener only needs ids, and a
 * model pulled here is a query per audited write.
 */
interface NotifiesOnChange
{
    /**
     * Who to tell when this record changes. Whoever made the change is removed by the
     * caller, so a model never has to think about it.
     *
     * @return iterable<int|\Illuminate\Database\Eloquent\Model|null>
     */
    public function changeAudience(): iterable;
}
