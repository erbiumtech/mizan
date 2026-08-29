<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Events\PayslipReviewed;
use App\Modules\Payroll\Services\PayComponentRecorder;
use App\Modules\Payroll\Services\PayrollPostingService;
use App\Modules\Payroll\Services\PayslipService;
use App\Modules\Payroll\Services\TaxCalculatorService;
use App\Notifications\PayslipObjectionAnswered;
use App\Notifications\PayslipRejected;
use App\Notifications\PayslipReturnedForReview;
use App\Support\Contracts\AdvanceLedger;
use App\Support\Contracts\OwnedByUser;
use App\Support\Contracts\ReimbursableClaims;
use App\Support\Impersonation;
use App\Support\PayrollMonth;
use App\Support\PayslipSettlement;
use App\Support\TenantTransaction;
use App\Traits\Auditable;
use App\Traits\HasComments;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class Payslip extends Model implements OwnedByUser
{
    use Auditable, HasComments;

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    /**
     * Rejected, then answered by payroll — the state that lets the salary go out.
     *
     * A rejection is advisory for the *payslip*: the figures can still be corrected, and nothing stops
     * anybody editing it. It is not advisory for the **money**. `Payment::isReleasable()` holds a salary back
     * until the payslip is accepted, and `recordEmployeeReview()` refuses a second review — so an employee
     * who objected to a payslip that turned out to be right left their own salary in a state no screen could
     * clear.
     *
     * This is that state, and it deliberately does not say "accepted": the employee did not accept it.
     * It says a person with `PayslipUpdate` read the objection, answered it in writing, and released the
     * payment on their own authority — which is what the record should show a year later.
     */
    public const REVIEW_OVERRIDDEN = 'overridden';

    protected $fillable = [
        'employee_id', 'month', 'fiscal_year_id', 'total_working_days', 'paid_days', 'lop_days',
        'leaves_taken',
        // Phase 3/3a: what the calculation RECORDED about this month, so a
        // recalculation reproduces it rather than re-deriving it from settings that
        // may since have changed. See AttendanceProration::recordedBasis().
        'proration_divisor', 'proration_basis_days',
        'overtime_minutes', 'overtime_hourly_rate', 'overtime_multiplier',
        'basic_wage', 'medical_allowance', 'device_allowance',
        'petrol_allowance', 'extra_work_hours', 'bonus', 'withholding_tax',
        'advances', 'meal_deduction', 'esi_health_insurance', 'annual_income_tax', 'total_net_income', 'total_earnings',
        'total_deductions', 'net_salary',
        // pdf_path is deliberately absent. The payslip PDF is rendered on request
        // from the payslip as it stands, never stored — see
        // PayslipService::renderPdf(). A path here would be a promise that the file
        // it names still matches the figures, and it did not.
        'employee_review', 'employee_reviewed_at', 'employee_rejection_reason', 'expense_reimbursement',
        'employee_review_recorded_by', 'employee_review_recorded_by_name',
        'review_objection_comment_id', 'review_overridden_by', 'review_overridden_by_name',
        'review_overridden_at',
    ];

    protected $casts = [
        'employee_reviewed_at' => 'datetime',
        'review_overridden_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /** Has the employee been sent this payslip? See PayslipDeliveryService. */
    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Was this acknowledgement entered by somebody other than the employee?
     */
    public function reviewWasRecordedOnBehalf(): bool
    {
        return $this->employee_review_recorded_by !== null;
    }

    /**
     * The note the payslip carries when it was: "Accepted on behalf of the
     * employee by …". Null when the employee acknowledged it themselves.
     */
    public function reviewOnBehalfNote(): ?string
    {
        if (! $this->reviewWasRecordedOnBehalf()) {
            return null;
        }

        $who = $this->employee_review_recorded_by_name ?: 'an administrator';
        $verb = $this->employee_review === self::REVIEW_REJECTED ? 'Rejected' : 'Accepted';
        $when = $this->employee_reviewed_at?->format('d M Y');

        return "{$verb} on behalf of the employee by {$who}".($when ? " on {$when}" : '').'.';
    }

    public function isPendingReview(): bool
    {
        return ($this->employee_review ?? self::REVIEW_PENDING) === self::REVIEW_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->employee_review === self::REVIEW_REJECTED;
    }

    public function isReviewOverridden(): bool
    {
        return $this->employee_review === self::REVIEW_OVERRIDDEN;
    }

    /**
     * The objection, written into the payslip's own comment thread.
     *
     * The reason is kept on the column as well, and that is not duplication for its own sake: the column is
     * what `CopyReviewOntoPayment` puts on the payment and what the bank file shows as the reason a salary
     * is held back, and neither of those can read a thread. The comment is the *conversation* — it is what
     * payroll replies to and what the employee sees when they open their payslip.
     *
     * Authored by whoever is signed in, which during impersonation is the employee themselves. That is the
     * right author: the on-behalf note beside it already records who actually typed it.
     */
    protected function openObjection(?string $reason): ?Comment
    {
        if (! auth()->check()) {
            return null;
        }

        return $this->comments()->create([
            'user_id' => auth()->id(),
            'body' => trim((string) $reason) ?: 'Payslip rejected; no reason given.',
        ]);
    }

    /** The comment the objection was written into, if this payslip has one. */
    public function objectionComment()
    {
        return $this->belongsTo(Comment::class, 'review_objection_comment_id');
    }

    /**
     * Has anybody replied to the objection yet?
     *
     * The gate on closing it. An objection answered before anybody has said anything back is a salary
     * released over a complaint nobody engaged with, which is the thing this whole flow exists to prevent —
     * so the reply has to exist, and it has to have come *after* the objection was raised.
     *
     * Any comment counts, from either side, because the payroll team replying is what the employee is owed
     * and requiring the employee to reply *again* would hand a silent employee the power to hold their own
     * salary indefinitely — the dead end this feature removes.
     */
    public function objectionHasReply(): bool
    {
        if (! $this->isRejected() && ! $this->isReviewOverridden()) {
            return false;
        }

        return $this->objectionReplies()->isNotEmpty();
    }

    /**
     * Everything said after the objection was raised, oldest first.
     *
     * **Read from the loaded relation when there is one**, and that is not an optimisation — it is what
     * keeps this callable from a table. The payslips list asks every row whether its objection has been
     * replied to, and a version that queried per row was an N+1 that `preventLazyLoading` turned into a
     * failing test rather than a slow screen. `PayslipsTable` eager-loads `comments`; anything else falls
     * back to the query.
     *
     * Bounded by `employee_reviewed_at` rather than by the objection comment's own timestamp: the two are
     * written in the same breath, and reading the comment would be the lazy load this method exists to
     * avoid. The objection itself is excluded by key.
     *
     * @return \Illuminate\Support\Collection<int, Comment>
     */
    public function objectionReplies(): \Illuminate\Support\Collection
    {
        $raisedAt = $this->employee_reviewed_at;

        if ($raisedAt === null) {
            return collect();
        }

        $replies = $this->relationLoaded('comments')
            ? $this->comments
            : $this->comments()->where('created_at', '>=', $raisedAt)->get();

        return $replies
            ->filter(fn (Comment $comment): bool => $comment->getKey() !== $this->review_objection_comment_id
                && $comment->created_at !== null
                && $comment->created_at->greaterThanOrEqualTo($raisedAt))
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Close the objection and let the salary go.
     *
     * The mirror of `recordEmployeeReview()`, and deliberately not part of it: that method is the
     * *employee's* statement about their own payslip and refuses to be made twice, which is what makes an
     * acknowledgement worth anything. This is somebody else's decision about the same document.
     *
     * **What is said is said in the thread, not here.** An earlier version of this took a reply as an
     * argument and stored it in a column of its own, which put half the conversation in one place and half
     * in another. The reply is a comment like every other; this method records only the decision — who
     * closed it and when — and marks the objection comment resolved, which is what the Comments tab shows
     * and what stops it being edited afterwards.
     *
     * **The rejection is not erased.** `employee_rejection_reason` stays exactly as the employee wrote it and
     * the state becomes `overridden`, which reads as "objected to, answered, released" rather than as an
     * acceptance nobody gave.
     */
    public function resolveObjection(): self
    {
        if (! $this->isRejected()) {
            throw new InvalidArgumentException(
                $this->isReviewOverridden()
                    ? 'This objection has already been answered.'
                    : "There is no objection to answer: this payslip is {$this->employee_review}."
            );
        }

        if (! $this->objectionHasReply()) {
            throw new InvalidArgumentException(
                'Nobody has replied to the objection yet. Answer it in the payslip\'s comments first — '
                .'releasing the salary over a complaint nobody has responded to is what this step exists to '
                .'prevent.'
            );
        }

        $by = auth()->user();

        // The decision and the resolved comment together, for the reason `recordEmployeeReview()` gives: a
        // payslip that says released while the thread still shows an open objection is a screen disagreeing
        // with itself.
        TenantTransaction::run(function () use ($by): void {
            $this->update([
                'employee_review' => self::REVIEW_OVERRIDDEN,
                // The name is snapshotted beside the id for the reason the on-behalf note gives: `users` is a
                // landlord table, and this has to still read after that account is renamed or removed.
                'review_overridden_by' => $by?->getKey(),
                'review_overridden_by_name' => $by?->name,
                'review_overridden_at' => now(),
            ]);

            // The thread says so too. `CommentPolicy` stops a resolved comment being edited, so this also
            // fixes the wording of the objection as it stood when the decision was taken.
            $this->objectionComment?->update([
                'resolved_at' => now(),
                'resolved_by' => $by?->getKey(),
            ]);
        });

        // The salary payment waiting on this reads its own copy of the review — see CopyReviewOntoPayment,
        // whose docblock asks that any fourth way of setting a review fire this event. This is that fourth
        // way, and the release depends on the copy being right.
        PayslipReviewed::dispatch($this);

        // The employee is told, because a decision they never see is not an answer. To them and to nobody
        // else: the staff who hold PayslipUpdate were told about the objection and are the ones closing it.
        if ($this->employee?->user) {
            Notification::send($this->employee->user, new PayslipObjectionAnswered($this));
        }

        return $this;
    }

    /**
     * Put the payslip back to the employee, corrected.
     *
     * **The other of the two ways to deal with an objection**, and the one that was missing. `resolveObjection()`
     * says *the payslip was right and here is why*; this one says *you were right, it has been changed, look
     * again*. Without it, correcting the figures left the payslip stuck on the employee's old rejection —
     * `recordEmployeeReview()` refuses a second review — so the person who was right about their pay could
     * never accept the corrected version, and the salary stayed held until somebody overrode a complaint
     * that had already been met.
     *
     * **The review goes back to pending and the thread does not.** Everything said stays where it was said,
     * and the note explaining what changed is posted to it, so the employee opens the payslip and sees why
     * they are being asked again. What is cleared is the *decision*: the state, its date, the reason on the
     * column, and the link to the objection — because the next rejection, if there is one, is about the new
     * figures and deserves its own.
     *
     * The salary goes back to being held, which is right: it is a fresh document awaiting a fresh
     * acknowledgement.
     *
     * @param  string  $note  What changed. Required — "look at it again" with no reason is how a payslip
     *                        goes round twice.
     */
    public function returnForReview(string $note): self
    {
        if (! $this->isRejected() && ! $this->isReviewOverridden()) {
            throw new InvalidArgumentException(
                'Only a payslip the employee has objected to can be sent back for review. This one is '
                ."{$this->employee_review}."
            );
        }

        if (trim($note) === '') {
            throw new InvalidArgumentException(
                'Say what changed. The employee is being asked to look at the same payslip a second time, '
                .'and a request with no reason is one they cannot act on.'
            );
        }

        TenantTransaction::run(function () use ($note): void {
            if (auth()->check()) {
                $this->comments()->create(['user_id' => auth()->id(), 'body' => trim($note)]);
            }

            $this->update([
                'employee_review' => self::REVIEW_PENDING,
                'employee_reviewed_at' => null,
                'employee_rejection_reason' => null,
                'employee_review_recorded_by' => null,
                'employee_review_recorded_by_name' => null,
                'review_objection_comment_id' => null,
                'review_overridden_by' => null,
                'review_overridden_by_name' => null,
                'review_overridden_at' => null,
            ]);
        });

        // The payment's copy goes back to pending with it, so a corrected payslip is not released on the
        // strength of an acknowledgement of the figures it used to carry.
        PayslipReviewed::dispatch($this);

        if ($this->employee?->user) {
            Notification::send($this->employee->user, new PayslipReturnedForReview($this, trim($note)));
        }

        return $this;
    }

    /**
     * The last thing anybody said about the objection.
     *
     * What the employee's notification quotes and what the list shows under the badge: the thread is the
     * record, so the answer is read from it rather than from a column beside it.
     */
    public function latestObjectionReply(): ?Comment
    {
        if ($this->review_objection_comment_id === null) {
            return null;
        }

        return $this->objectionReplies()->last();
    }

    /**
     * The one line a screen shows about a closed objection: who closed it and when.
     *
     * Null when there has been no override, which is every payslip but the few that were objected to.
     */
    public function reviewOverrideNote(): ?string
    {
        if (! $this->isReviewOverridden()) {
            return null;
        }

        $who = $this->review_overridden_by_name ?: 'a member of the payroll team';
        $when = $this->review_overridden_at?->format('d M Y');

        return "Objection closed by {$who}".($when ? " on {$when}" : '').'.';
    }

    /**
     * Employee acknowledgement of the payslip. Rejection is advisory:
     * it records the objection for the accounts team but blocks nothing.
     */
    public function recordEmployeeReview(string $status, ?string $reason = null): self
    {
        if (! in_array($status, [self::REVIEW_ACCEPTED, self::REVIEW_REJECTED])) {
            throw new InvalidArgumentException("Invalid review status {$status}.");
        }

        if (! $this->isPendingReview()) {
            throw new InvalidArgumentException("Payslip has already been reviewed ({$this->employee_review}).");
        }

        // Who is really at the keyboard. An administrator signed in as the employee
        // (App\Support\Impersonation) may legitimately enter this for staff who
        // cannot, but accepting a payslip is a statement of consent — so the
        // payslip records that it was entered on their behalf, and by whom, rather
        // than presenting it as the employee's own acknowledgement. Left null in
        // the ordinary case, where the employee did it themselves.
        $onBehalfOf = app(Impersonation::class)->impersonator();

        /*
         * The comment and the review land together, or neither does.
         *
         * The objection is written into the thread first, because the update needs its id — so a failure on
         * the update leaves a comment on the payslip that nobody said, attached to a rejection that never
         * happened. That is not hypothetical: it is what a stale schema produced on a developer machine
         * before this wrapper existed, and the leftover reads exactly like a real objection.
         */
        TenantTransaction::run(function () use ($status, $reason, $onBehalfOf): void {
            $this->update([
                'employee_review' => $status,
                'employee_reviewed_at' => now(),
                'employee_rejection_reason' => $status === self::REVIEW_REJECTED ? $reason : null,
                'employee_review_recorded_by' => $onBehalfOf?->getKey(),
                'employee_review_recorded_by_name' => $onBehalfOf?->name,
                'review_objection_comment_id' => $status === self::REVIEW_REJECTED
                    ? $this->openObjection($reason)?->getKey()
                    : null,
            ]);
        });

        // Anything holding a copy of this decision updates itself now. Today that is the salary payment,
        // which refuses to be released until the payslip is accepted and reads its own column rather than
        // this one — see docs/module-packaging-plan.md §8 Group C. Fired after the update so a listener
        // reads the new state, and before the notification so a rejection cannot be told to staff while a
        // payment still looks releasable.
        PayslipReviewed::dispatch($this);

        if ($status === self::REVIEW_REJECTED) {
            $staff = User::holdingPermission('PayslipUpdate')
                ->where('id', '!=', $this->employee?->user_id)
                ->where('status', 1)
                ->get();

            Notification::send(
                $staff,
                new PayslipRejected($this)
            );
        }

        return $this;
    }

    /**
     * Whose payslip this is.
     *
     * Answers App\Support\Contracts\OwnedByUser, which is how `CommentPolicy` lets an employee read the
     * comments on their own payslip without Core's policy knowing what a payslip is.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->employee !== null && $this->employee->user_id === $user->getKey();
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function components()
    {
        return $this->hasMany(PayslipComponent::class);
    }

    public function journalEntries()
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    /**
     * This payslip as something another module's ledger can settle against.
     *
     * The advance ledger and the claims process both attach to a payslip, and both live in modules that
     * *require* Payroll — so the contracts they are asked through cannot name this class, or shared code would
     * be undeployable without this module. They get the four values they read from one instead. See
     * `App\Support\PayslipSettlement` and docs/module-packaging-plan.md §11.
     *
     * **The date is computed here, and that is the point.** "The month payroll took it, not the day the row
     * was written" is a payroll fact: a July payslip is often processed in August, and dating a recovery by
     * when somebody pressed save would put July's instalment in August — visible on the advance's own history,
     * and wrong on any bill that credits a month's repayments back. This was `AdvanceService::recoveryDate()`,
     * and it belongs on this side of the boundary.
     *
     * @param  float  $amount  what the payslip took or pays back — `advances` or `expense_reimbursement`
     */
    public function settlementOf(float $amount): PayslipSettlement
    {
        return new PayslipSettlement(
            payslipId: $this->getKey(),
            employeeId: $this->employee_id,
            amount: $amount,
            effectiveOn: PayrollMonth::lastDay($this->month, $this->fiscalYear)->toDateString(),
        );
    }

    protected static function booted()
    {
        /*
         * A locked month cannot be changed.
         *
         * Enforced on the model rather than in a policy, because Administrators and
         * super admins pass every policy check — and a sign-off that the most
         * privileged user can walk through is not a sign-off. Reopening the run is the
         * way past this, and it leaves a reason behind.
         */
        $refuseIfLocked = function ($payslip, string $verb): void {
            $run = $payslip->payroll_run_id
                ? PayrollRun::find($payslip->payroll_run_id)
                : null;

            if ($run?->isLocked()) {
                throw new InvalidArgumentException(
                    "{$run->periodLabel()} payroll has been signed off, so this payslip cannot be {$verb}. "
                    .'Reopen the run if it genuinely has to change.'
                );
            }
        };

        static::updating(function ($payslip) use ($refuseIfLocked) {
            // An employee accepting or rejecting their payslip is not a change to the
            // month's figures, and refusing it would leave them unable to respond to
            // something already sent to them.
            if (static::isReviewOnlyChange($payslip->getDirty())) {
                return;
            }

            $refuseIfLocked($payslip, 'changed');
        });

        static::deleting(fn ($payslip) => $refuseIfLocked($payslip, 'deleted'));

        // Attached to its month's run, made if this is the first payslip of the month,
        // so nothing depends on whoever created the payslip remembering — and then
        // refused if that month has been signed off.
        //
        // One hook, in that order, deliberately. As two the refusal ran first, saw no
        // run id yet and returned, and the attachment then put the payslip into the
        // locked run: the control held only against callers who happened to name the
        // run themselves.
        static::creating(function ($payslip) use ($refuseIfLocked) {
            if (! $payslip->payroll_run_id && $payslip->month && $payslip->fiscal_year_id) {
                $fiscalYear = FiscalYear::find($payslip->fiscal_year_id);

                if ($fiscalYear) {
                    $payslip->payroll_run_id = PayrollRun::forMonth($payslip->month, $fiscalYear)->getKey();
                }
            }

            $refuseIfLocked($payslip, 'added');
        });

        $calculate = function ($payslip) {
            // Employee accept/reject only touches review columns; the
            // figures and ledger posting must stay as issued.
            if ($payslip->exists && static::isReviewOnlyChange($payslip->getDirty())) {
                return;
            }

            $service = app(PayslipService::class);

            $calculatedData = $service->calculateByParams(
                $payslip->employee_id,
                $payslip->month,
                $payslip->fiscal_year_id,
                $payslip->bonus,
                $payslip->extra_work_hours,
                $payslip->device_allowance,
                $payslip->petrol_allowance,
                $payslip->advances,
                $payslip->meal_deduction,
                $payslip->esi_health_insurance,
                $payslip->expense_reimbursement,
                $payslip->id,
                // Phase 3/3a. What the payslip knows about the month: the pro-rating
                // inputs, and whatever it has already recorded about how it was
                // calculated. Passing the recorded values is what stops a settled
                // month moving when a company later changes the divisor — this hook
                // re-runs on EVERY save, including a clerk fixing a typo.
                [
                    'total_working_days' => $payslip->total_working_days,
                    'lop_days' => $payslip->lop_days,
                    'proration_divisor' => $payslip->proration_divisor,
                    'proration_basis_days' => $payslip->proration_basis_days,
                    'overtime_minutes' => $payslip->overtime_minutes,
                    'overtime_hourly_rate' => $payslip->overtime_hourly_rate,
                    'overtime_multiplier' => $payslip->overtime_multiplier,
                ]
            );

            if ($calculatedData) {
                $payslip->basic_wage = $calculatedData['basic_wage'];
                $payslip->medical_allowance = $calculatedData['medical_allowance'];
                $payslip->device_allowance = $calculatedData['device_allowance'];
                $payslip->petrol_allowance = $calculatedData['petrol_allowance'];
                $payslip->bonus = $calculatedData['bonus'];
                $payslip->extra_work_hours = $calculatedData['extra_work_hours'];
                $payslip->expense_reimbursement = $calculatedData['expense_reimbursement'];
                $payslip->withholding_tax = $calculatedData['withholding_tax'];
                $payslip->advances = $calculatedData['advances'];
                $payslip->meal_deduction = $calculatedData['meal_deduction'];
                $payslip->esi_health_insurance = $calculatedData['esi_health_insurance'];
                $payslip->total_earnings = $calculatedData['total_earnings'];
                $payslip->total_deductions = $calculatedData['total_deductions'];
                $payslip->net_salary = $calculatedData['net_salary'];

                // Written back so the next recalculation reproduces this one. Null
                // when nothing was pro-rated or no overtime was paid, which clears a
                // stale basis on a payslip whose attendance was corrected to a full
                // month.
                $payslip->proration_divisor = $calculatedData['proration_divisor'] ?? null;
                $payslip->proration_basis_days = $calculatedData['proration_basis_days'] ?? null;
                $payslip->overtime_minutes = $calculatedData['overtime_minutes'] ?? null;
                $payslip->overtime_hourly_rate = $calculatedData['overtime_hourly_rate'] ?? null;
                $payslip->overtime_multiplier = $calculatedData['overtime_multiplier'] ?? null;
            }
        };

        static::creating($calculate);
        static::updating($calculate);

        $syncTax = function ($payslip) {
            self::calculateAndStoreAnnualTax($payslip->employee_id, $payslip->fiscal_year_id);
        };

        static::saved(function ($payslip) use ($syncTax) {
            if (! static::isReviewOnlyChange($payslip->getChanges())) {
                $syncTax($payslip);
            }
        });
        static::deleted($syncTax);

        // Book the deduction against the employee's advances, so the balance owed
        // follows what payroll actually took. Idempotent per payslip: payroll
        // recalculates on every save, and a second row would recover the same
        // instalment twice.
        //
        // Asked of a contract, not of `Advances\Services\AdvanceService`. Advances *requires* Payroll, so
        // naming it here was a two-cycle — the last one in the application together with the identical one
        // through Expenses. The licence guard went with the implementation; the null default does nothing.
        // See App\Support\Contracts\AdvanceLedger and docs/module-packaging-plan.md §11.
        static::saved(function (Payslip $payslip) {
            if (! static::isReviewOnlyChange($payslip->getChanges())) {
                app(AdvanceLedger::class)->recordRecoveryFor($payslip->settlementOf((float) $payslip->advances));
            }
        });

        // Deleting the payslip gives its recovery back — the money was never
        // taken, so the balance must go up again. The cascade on payslip_id does
        // this at the database level; settling is what needs correcting, and *when* an
        // advance is owed again is the ledger's judgement rather than payroll's.
        static::deleted(function (Payslip $payslip) {
            app(AdvanceLedger::class)->reopenSettledFor($payslip->employee_id);
        });

        // What this payslip actually paid, component by component.
        //
        // Recorded rather than derived, because a package corrected in September must
        // not change what August paid. Written on every save and reconciled rather than
        // appended, so a corrected payslip's components follow its columns.
        static::saved(function ($payslip) {
            if (static::isReviewOnlyChange($payslip->getChanges())) {
                return;
            }

            app(PayComponentRecorder::class)->record($payslip);
        });

        // Expense claims the payslip reimburses. Same shape as the advances above, and the same inversion:
        // asked of App\Support\Contracts\ReimbursableClaims, because Expenses requires Payroll too.
        static::saved(function (Payslip $payslip) {
            if (! static::isReviewOnlyChange($payslip->getChanges())) {
                app(ReimbursableClaims::class)
                    ->settleForPayslip($payslip->settlementOf((float) $payslip->expense_reimbursement));
            }
        });

        // Two phases, because expense_claims.payslip_id is nullOnDelete: by the time
        // `deleted` runs the database has already cut the link, so the claims have to
        // be noted while the payslip still exists and released once it is gone.
        //
        // The noted list stays here rather than becoming state on the implementation, which would then have
        // to survive between two callbacks — and would be shared by every payslip deleted in the request.
        $claimsToRelease = [];

        static::deleting(function (Payslip $payslip) use (&$claimsToRelease) {
            $claimsToRelease = app(ReimbursableClaims::class)->pendingReleaseFor($payslip->getKey());
        });

        static::deleted(function (Payslip $payslip) use (&$claimsToRelease) {
            app(ReimbursableClaims::class)->releaseAll($claimsToRelease);

            $claimsToRelease = [];
        });

        // Ledger integration: (re)create the payroll journal entry on save,
        // reverse/remove it on delete.
        static::saved(function ($payslip) {
            if (! static::isReviewOnlyChange($payslip->getChanges())) {
                app(PayrollPostingService::class)->postPayslip($payslip);
            }
        });

        static::deleted(function ($payslip) {
            app(PayrollPostingService::class)->unwindForPayslip($payslip);
        });
    }

    protected static function isReviewOnlyChange(array $dirty): bool
    {
        unset($dirty['updated_at']);

        return $dirty !== []
            && array_diff_key($dirty, array_flip([
                'employee_review', 'employee_reviewed_at', 'employee_rejection_reason',
                'employee_review_recorded_by', 'employee_review_recorded_by_name',
                // Overriding a rejection is a review change too — it answers one. Left out of this list it
                // would read as an edit to the payslip's figures, and the observers below would unwind and
                // repost the payroll entries for a sentence somebody typed.
                'review_objection_comment_id', 'review_overridden_by', 'review_overridden_by_name',
                'review_overridden_at',
            ])) === [];
    }

    public static function calculateAndStoreAnnualTax($employeeId, $fiscalYearId)
    {
        $payslips = self::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->get();

        if ($payslips->isEmpty()) {
            AnnualTax::where('employee_id', $employeeId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->delete();

            return;
        }

        $totalActualIncomeYearToDate = 0;
        $totalNetSalaryYearToDate = 0;
        $totalPaidTaxYearToDate = 0;
        $monthsCount = $payslips->count();

        foreach ($payslips as $p) {
            $totalActualIncomeYearToDate += (float) ($p->total_earnings ?? 0);
            $totalNetSalaryYearToDate += (float) ($p->net_salary ?? 0);
            $totalPaidTaxYearToDate += (float) ($p->withholding_tax ?? 0);
        }

        $allSettings = EmployeeSetting::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->get();

        $annualTotalEarnings = 0;
        $completedMonths = $payslips->pluck('month')->toArray();

        if ($allSettings->isNotEmpty()) {
            foreach ($allSettings as $empSetting) {
                $sMonthlyTotal = (float) $empSetting->basic_wage
                                 + (float) $empSetting->medical_allowance
                                 + (float) $empSetting->petrol_allowance
                                 + (float) $empSetting->device_allowance
                                 + (float) ($empSetting->bonus ?? 0)
                                 + (float) ($empSetting->extra_work_hours ?? 0);

                $sStart = Carbon::parse($empSetting->start_date);
                $sEnd = Carbon::parse($empSetting->end_date);

                $currentPeriodCursor = $sStart->copy();
                while ($currentPeriodCursor <= $sEnd) {
                    $mName = $currentPeriodCursor->format('F');

                    $matchedPayslip = $payslips->firstWhere('month', $mName);
                    if ($matchedPayslip) {
                        $annualTotalEarnings += (float) $matchedPayslip->total_earnings;
                    } else {
                        $annualTotalEarnings += $sMonthlyTotal;
                    }

                    $currentPeriodCursor->addMonth();
                }
            }
        } else {
            $avgMonthlyIncome = $monthsCount > 0 ? ($totalActualIncomeYearToDate / $monthsCount) : 0;
            $remainingMonths = max(0, 12 - $monthsCount);
            $annualTotalEarnings = $totalActualIncomeYearToDate + ($avgMonthlyIncome * $remainingMonths);
        }

        // Net salary projected average
        $avgMonthlyNet = $monthsCount > 0 ? ($totalNetSalaryYearToDate / $monthsCount) : 0;
        $annualProjectedNetIncome = $avgMonthlyNet * 12;

        $medicalExemption = $annualTotalEarnings * 0.10;

        // Annual Taxable Income = Total Earnings - 10% Exemption Cut
        $annualTaxableIncome = max(0, $annualTotalEarnings - $medicalExemption);

        $annualTotalTax = app(TaxCalculatorService::class)
            ->annualTax((float) $annualTaxableIncome, $fiscalYearId);
        $leftoverTax = max(0, $annualTotalTax - $totalPaidTaxYearToDate);

        AnnualTax::updateOrCreate(
            [
                'employee_id' => $employeeId,
                'fiscal_year_id' => $fiscalYearId,
            ],
            [
                'total_annual_income' => round($annualTotalEarnings, 2),
                'annual_income_tax' => round($annualTaxableIncome, 2),
                'total_net_income' => round($annualProjectedNetIncome, 2),
                'total_annual_tax' => round($annualTotalTax, 2),
                'paid_tax' => round($totalPaidTaxYearToDate, 2),
                'leftover_tax' => round($leftoverTax, 2),
            ]
        );
    }

    public static function syncAnnualTax($employeeId, $fiscalYearId)
    {
        self::calculateAndStoreAnnualTax($employeeId, $fiscalYearId);
    }
}
