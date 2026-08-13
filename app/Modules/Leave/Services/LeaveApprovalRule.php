<?php

namespace App\Modules\Leave\Services;

use App\Support\SelfApprovalPolicy;

/**
 * Whether leave must be approved by somebody other than the person who filed it.
 *
 * The same question the ledger asks, so it reuses the same mechanics rather than
 * inventing a second answer — docs/hrms-plan.md §4.1 says exactly that, and §11
 * names SecondApproverRule as the asset worth generalising when leave became its
 * second caller.
 *
 * The dead end it has to accommodate is the tree, not the company: a manager filing
 * their own leave routes to *their* manager, and the employee at the top has nobody
 * above them. With the rule on, that person cannot file leave at all unless somebody
 * privileged decides it; with it off, they may decide their own and the activity log
 * says so.
 */
class LeaveApprovalRule extends SelfApprovalPolicy
{
    public const SETTING_KEY = 'leave.require_second_approver';

    public function settingKey(): string
    {
        return self::SETTING_KEY;
    }
}
