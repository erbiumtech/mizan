<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\Core\Models\User;

/**
 * Who may build, approve and baseline a job budget — §3.5's three separate decisions.
 *
 * They are three permissions rather than one because they are held by three different people. The surveyor prices
 * the budget; approving it fixes what the cost report compares actuals to; **setting the baseline decides what
 * every earned-value figure on the job is measured against, and re-setting it restates all of them** — so it sits
 * with whoever answers for the numbers.
 *
 * As with the cost entry, `update` answers "may this role edit at all" and whether *this* version is still a draft
 * is `BudgetService`'s. Keeping them apart matters: a permission grant must not be able to edit an approved budget,
 * because a variance measured against a budget that moves means nothing.
 */
class JobBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionBudgetView');
    }

    public function view(User $user, JobBudget $version): bool
    {
        return $user->can('ConstructionBudgetView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionBudgetUpdate');
    }

    public function update(User $user, JobBudget $version): bool
    {
        return $user->can('ConstructionBudgetUpdate') && $version->status === JobBudget::STATUS_DRAFT;
    }

    /** Approving is what makes a version the budget the job reports against. */
    public function approve(User $user, JobBudget $version): bool
    {
        return $user->can('ConstructionBudgetApprove') && $version->status !== JobBudget::STATUS_APPROVED;
    }

    /** Baselining is the one that restates history if it moves. */
    public function setBaseline(User $user, JobBudget $version): bool
    {
        return $user->can('ConstructionBudgetBaseline') && ! $version->is_baseline;
    }

    /**
     * A draft with no lines is deletable; anything else is not.
     *
     * A superseded version is the answer to "what did we think the budget was before VO-12", and an approved one
     * may have measurements frozen against it. Deleting either leaves earned-value figures pointing at a version
     * nobody can see.
     */
    public function delete(User $user, JobBudget $version): bool
    {
        return $user->can('ConstructionBudgetUpdate')
            && $version->status === JobBudget::STATUS_DRAFT
            && ! $version->is_baseline
            && $version->lines()->doesntExist();
    }
}
