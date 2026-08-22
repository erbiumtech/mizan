<?php

use App\Modules\Recruitment\Models\Applicant;
use Illuminate\Support\Facades\Schedule;

/**
 * Applicant retention.
 *
 * The only scheduled work this module needs, and it is a data-protection obligation
 * rather than a feature: this application holds CVs about people the company never
 * hired, and keeping them for ever is not a decision anybody made.
 *
 * Applicant::prunable() keys on the REJECTION rather than the row's age, so somebody
 * still in a process — or hired — is never pruned. The `pruning()` hook deletes the CV
 * file with the row, because a pruned record that leaves its file behind means the
 * company still holds the CV while believing it does not.
 *
 * Model named as a class CONSTANT, never a string: the string form fails at 00:00 in a
 * queue worker rather than in CI.
 */
Schedule::command('tenants:artisan', ['model:prune --model='.Applicant::class])
    ->daily();
