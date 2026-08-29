<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\EditPayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ViewPayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\RelationManagers\CommentsRelationManager;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Notifications\PayslipObjectionAnswered;
use App\Notifications\PayslipReturnedForReview;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The reason an employee gives for rejecting a payslip: collected, stored, and — as of now — *shown*.
 *
 * The collecting half was already built and already required: **Reject Payslip** opens a modal with a
 * required Reason, `Payslip::recordEmployeeReview()` stores it, `PayslipRejected` emails it to the payroll
 * team, `CopyReviewOntoPayment` copies it onto the salary payment, and the bank file names it as the reason
 * a row is held back. `PayslipReviewTest` covers the model. What nothing covered was the **action** — the
 * form that collects the reason — and what nothing did at all was put it on the screen the payroll team
 * works from: a rejected payslip was a red badge and a question whose answer was in somebody's inbox.
 *
 * So these tests are in three parts, and all of them are about the objection rather than the rejection:
 *
 *  - the action *requires* a reason and stores what was typed, through Livewire rather than through the
 *    model, because the model has never been the part that could quietly drop it;
 *  - the reason opens a **conversation**: it is written into the payslip's comment thread as its first
 *    comment, which is what the employee and the payroll team both read and reply to;
 *  - and closing the objection — the thing that releases the held salary — is refused until somebody has
 *    replied to it.
 */
class PayslipRejectionReasonTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // The employee themselves: `canReview()` allows the owning employee and nobody else, which is what
        // makes this the flow a person actually goes through rather than an administrator's shortcut.
        $this->actingAs($this->makeUser('Employee', 'rejecter@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => auth()->id(),
            'employee_id' => 'EMP-REJECT',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);
    }

    /** The modal will not submit without one — the point being that it never has, and now it is asserted. */
    public function test_the_reject_action_refuses_to_submit_without_a_reason(): void
    {
        $payslip = $this->payslip();

        Livewire::test(ListPayslips::class)
            ->callTableAction('rejectPayslip', $payslip, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => ['required']]);

        $this->assertSame(Payslip::REVIEW_PENDING, $payslip->refresh()->employee_review);
    }

    /** And what was typed is what is stored, not a summary of it. */
    public function test_the_reason_typed_into_the_action_is_what_is_stored(): void
    {
        $payslip = $this->payslip();

        Livewire::test(ListPayslips::class)
            ->callTableAction('rejectPayslip', $payslip, ['reason' => 'Overtime for the 14th is missing'])
            ->assertHasNoTableActionErrors();

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_REJECTED, $payslip->employee_review);
        $this->assertSame('Overtime for the 14th is missing', $payslip->employee_rejection_reason);
        $this->assertNotNull($payslip->employee_reviewed_at);
    }

    /**
     * Accepting stores no reason, which is the other half of the column being null most of the time.
     *
     * Worth pinning because `recordEmployeeReview()` takes the reason as an optional second argument: an
     * accept that carried one would put an objection on a payslip nobody objected to.
     */
    public function test_accepting_records_no_reason(): void
    {
        $payslip = $this->payslip();

        Livewire::test(ListPayslips::class)
            ->callTableAction('acceptPayslip', $payslip);

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_ACCEPTED, $payslip->employee_review);
        $this->assertNull($payslip->employee_rejection_reason);
    }

    /** The list says why, under the badge that says it was rejected. */
    public function test_the_list_shows_the_reason_under_the_review_badge(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        Livewire::test(ListPayslips::class)
            ->assertSee('rejected')
            ->assertSee('Overtime for the 14th is missing');
    }

    /**
     * A long objection is truncated rather than allowed to reflow the row.
     *
     * The whole sentence is still reachable — it is the column's tooltip, and it is on the payment this
     * rejection holds back — so nothing is lost by keeping the list readable.
     */
    public function test_a_long_reason_is_truncated_in_the_list(): void
    {
        $payslip = $this->payslip();
        $long = 'The overtime I worked on the 14th and again on the 21st has not been included, and the '
            .'petrol allowance looks like last month\'s figure rather than this one.';

        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, $long);

        // The truncated form itself, ellipsis included, and the tail present only because the tooltip carries
        // it. Asserting that the tail is *absent* would fail for the right reason — the whole sentence is
        // deliberately still on the page — and asserting the whole string in one piece fails for a boring
        // one: the apostrophe is escaped differently inside the tooltip attribute than in the cell.
        Livewire::test(ListPayslips::class)
            ->assertSee(Str::limit($long, 60))
            ->assertSee('petrol allowance looks like');
    }

    /** An accepted payslip carries no note at all, so the column stays one line for almost every row. */
    public function test_an_accepted_payslip_shows_no_note(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        Livewire::test(ListPayslips::class)
            ->assertSee('accepted')
            ->assertDontSee('on behalf');
    }

    // ─────────────────────────────────────────── answering the objection ──

    /**
     * The objection opens the conversation: it is the thread's first comment.
     *
     * Both sides read this thread — an employee holds `CommentCreate` and `CommentView`, and `CommentPolicy`
     * limits them to records they own — so writing the reason into it is what makes the exchange possible at
     * all. The column keeps its copy, because that is what reaches the payment and the bank file.
     */
    public function test_the_objection_is_written_into_the_comment_thread(): void
    {
        $payslip = $this->payslip();

        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');
        $payslip->refresh();

        $comment = $payslip->objectionComment;

        $this->assertNotNull($comment, 'the rejection did not open a thread');
        $this->assertSame('Overtime for the 14th is missing', $comment->body);
        $this->assertSame(auth()->id(), $comment->user_id);
        $this->assertSame(1, $payslip->comments()->count());
    }

    /**
     * A rejection that fails leaves nothing behind — not even the comment.
     *
     * The objection has to be written before the payslip can point at it, so a failure on the update used to
     * leave a comment nobody said, attached to a rejection that never happened. It reads exactly like a real
     * objection. Provoked here by making the update itself impossible.
     */
    public function test_a_failed_rejection_leaves_no_orphan_comment(): void
    {
        $payslip = $this->payslip();

        // The update is made impossible at the database, which is the same shape as the schema fault that
        // produced the leftover: the comment is written, and then the payslip refuses to be.
        try {
            DB::statement('CREATE TRIGGER refuse_payslip_update BEFORE UPDATE ON payslips '
                ."BEGIN SELECT RAISE(ABORT, 'refused'); END");

            $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

            $this->fail('the update was expected to fail');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS refuse_payslip_update');
        }

        $this->assertSame(0, $payslip->comments()->count(), 'a failed rejection left a comment behind');
        $this->assertSame(Payslip::REVIEW_PENDING, $payslip->refresh()->employee_review);
    }

    /** Accepting opens no thread — there is nothing to discuss. */
    public function test_accepting_opens_no_thread(): void
    {
        $payslip = $this->payslip();

        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $this->assertNull($payslip->refresh()->review_objection_comment_id);
        $this->assertSame(0, $payslip->comments()->count());
    }

    /**
     * Payroll replies, closes the objection, and the salary can go out.
     *
     * The gap this closes is not cosmetic: `recordEmployeeReview()` refuses a second review and
     * `Payment::isReleasable()` holds a salary until the payslip is accepted, so an employee who objected to
     * a payslip that turned out to be right left their own salary unreleasable from any screen.
     */
    public function test_payroll_can_close_an_answered_objection_and_release_the_salary(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->actingAs($this->makeUser('Accountant', 'answering-accountant@test.local'));
        $this->reply($payslip, 'Checked the timesheet — the 14th was a public holiday, already paid at the flat rate.');

        Livewire::test(ListPayslips::class)
            ->callTableAction('overrideRejection', $payslip)
            ->assertHasNoTableActionErrors();

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_OVERRIDDEN, $payslip->employee_review);
        $this->assertSame('Accountant', $payslip->review_overridden_by_name);
        $this->assertNotNull($payslip->review_overridden_at);

        // The objection is marked resolved in the thread, which is also what stops it being edited.
        $this->assertNotNull($payslip->objectionComment->refresh()->resolved_at);

        // And the objection itself is still there. That is the whole reason the state is not "accepted".
        $this->assertSame('Overtime for the 14th is missing', $payslip->employee_rejection_reason);
    }

    /**
     * It cannot be closed before anybody has replied — the rule the whole conversation exists for.
     *
     * Asserted at the model as well as on the screen, because the button being hidden is a courtesy and this
     * is the guarantee: a salary is not released over a complaint nobody responded to.
     */
    public function test_an_objection_cannot_be_closed_before_anybody_replies(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'hasty-accountant@test.local'));

        $this->assertFalse($payslip->refresh()->objectionHasReply());

        try {
            $payslip->resolveObjection();

            $this->fail('an objection nobody had replied to was closed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Nobody has replied', $e->getMessage());
        }

        $this->assertSame(Payslip::REVIEW_REJECTED, $payslip->refresh()->employee_review);
    }

    /** And the button says so rather than disappearing: disabled, with the reason on hover. */
    public function test_the_close_button_is_disabled_until_somebody_replies(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'waiting-accountant@test.local'));

        Livewire::test(ListPayslips::class)
            ->assertTableActionVisible('overrideRejection', $payslip)
            ->assertTableActionDisabled('overrideRejection', $payslip);

        $this->reply($payslip, 'Checked — the figure is right.');

        Livewire::test(ListPayslips::class)
            ->assertTableActionEnabled('overrideRejection', $payslip);
    }

    /** A reply from the employee counts too: what is required is that somebody said something back. */
    public function test_a_reply_from_either_side_opens_the_close(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        // The employee, adding to their own objection.
        $this->reply($payslip, 'It was the 14th and the 21st, not just the 14th.');

        $this->assertTrue($payslip->refresh()->objectionHasReply());
    }

    /**
     * The employee cannot answer their own objection.
     *
     * `PayslipUpdate` is what gates it, and an employee holds only `PayslipView` — so the action is not even
     * offered on their own payslip, which is where it would do the most damage.
     */
    public function test_the_employee_is_not_offered_the_override(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        Livewire::test(ListPayslips::class)
            ->assertTableActionHidden('overrideRejection', $payslip);
    }

    /** And it is not offered at all on a payslip nobody has objected to. */
    public function test_the_override_is_not_offered_without_an_objection(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->makeUser('Accountant', 'early-accountant@test.local'));

        Livewire::test(ListPayslips::class)
            ->assertTableActionHidden('overrideRejection', $payslip);
    }

    /** Twice is refused: the answer is a decision, not a running commentary. */
    public function test_an_objection_cannot_be_answered_twice(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'twice-accountant@test.local'));
        $this->reply($payslip, 'Checked, it is right.');
        $payslip->refresh()->resolveObjection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already been answered');

        $payslip->refresh()->resolveObjection();
    }

    /** The employee is told what was said, because an answer nobody sees is not an answer. */
    public function test_the_employee_is_told_the_answer(): void
    {
        Notification::fake();

        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'telling-accountant@test.local'));
        $this->reply($payslip, 'The 14th was a public holiday.');
        $payslip->refresh()->resolveObjection();

        Notification::assertSentTo(
            $this->employee->user,
            PayslipObjectionAnswered::class,
        );
    }

    /**
     * A signed-off month can still have its objections answered.
     *
     * The case where this matters most, and the one a lock could have broken: the run is closed, the salary
     * is sitting in the bank file held back by the objection, and nothing about answering it changes a
     * figure. `Payslip::isReviewOnlyChange()` is what lets it through, and the override columns had to be
     * named in that list or the observers would have tried to unwind and repost the payroll entries for a
     * sentence somebody typed.
     */
    public function test_an_objection_can_be_answered_after_the_month_is_signed_off(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $signer = $this->makeUser('Manager', 'signer@test.local');
        $this->actingAs($signer);
        PayrollRun::forMonth('July', $this->fiscalYear)->lock($signer);

        $this->reply($payslip, 'Checked against the timesheet; the figure is right.');
        $payslip->refresh()->resolveObjection();

        $this->assertSame(Payslip::REVIEW_OVERRIDDEN, $payslip->refresh()->employee_review);
    }

    // ───────────────────────────────────────────────── the conversation ──

    /**
     * The employee can reply on their own payslip, and see the whole thread.
     *
     * This is the half that did not exist: the Comments tab listed comments and offered no way to write one,
     * so an objection went out as an email and everything after it happened outside the application. The
     * permissions were always there — `CommentCreate` and `CommentView` are granted to Employee, and
     * `CommentPolicy` limits them to records they own.
     */
    public function test_the_employee_can_read_and_reply_on_their_own_payslip(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->thread($payslip)
            ->assertSee('Overtime for the 14th is missing')
            ->callAction(TestAction::make('create')->table(), ['body' => 'And the 21st as well.']);

        $this->assertSame(2, $payslip->comments()->count());
        $this->assertSame(auth()->id(), $payslip->comments()->latest('id')->first()->user_id);
    }

    /** And payroll replies in the same place, which is what the employee reads. */
    public function test_payroll_replies_in_the_same_thread(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $accountant = $this->makeUser('Accountant', 'threading-accountant@test.local');
        $this->actingAs($accountant);

        $this->thread($payslip)
            ->callAction(TestAction::make('create')->table(), ['body' => 'The 14th was a public holiday.']);

        $payslip->refresh();

        $this->assertTrue($payslip->objectionHasReply());
        $this->assertSame('The 14th was a public holiday.', $payslip->latestObjectionReply()->body);
        $this->assertSame($accountant->getKey(), $payslip->latestObjectionReply()->user_id);
    }

    /** The tab says when an objection is waiting on somebody, and stops saying it once answered. */
    public function test_the_comments_tab_flags_an_objection_waiting_for_a_reply(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->assertSame(
            'Needs a reply',
            CommentsRelationManager::getBadge($payslip->refresh(), EditPayslip::class),
        );

        $this->reply($payslip, 'Checked — the figure is right.');

        $this->assertNull(CommentsRelationManager::getBadge($payslip->refresh(), EditPayslip::class));
    }

    /**
     * **Mark solved** in the thread closes the objection — the whole objection, not just the comment.
     *
     * The trap this avoids: resolving the comment and leaving the payslip rejected would look finished and
     * change nothing — the state would still read *rejected* and the salary would still be held. So the
     * action on the objection comment goes through `resolveObjection()`, the same method the page buttons
     * call.
     */
    public function test_marking_the_objection_solved_closes_it_and_releases_the_salary(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->actingAs($this->makeUser('Accountant', 'solving-accountant@test.local'));
        $this->reply($payslip, 'Checked — the 14th was a public holiday.');

        $this->thread($payslip)
            ->callAction(TestAction::make('resolveComment')->table($payslip->refresh()->objectionComment));

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_OVERRIDDEN, $payslip->employee_review);
        $this->assertNotNull($payslip->objectionComment->refresh()->resolved_at);
        $this->assertSame('Accountant', $payslip->review_overridden_by_name);
    }

    /** Before anybody has replied it is disabled, for the same reason the page button is. */
    public function test_marking_the_objection_solved_is_disabled_until_somebody_replies(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'early-solver@test.local'));

        $this->thread($payslip)
            ->assertActionDisabled(TestAction::make('resolveComment')->table($payslip->refresh()->objectionComment));

        $this->assertSame(Payslip::REVIEW_REJECTED, $payslip->refresh()->employee_review);
    }

    /** An ordinary comment marked solved is just that: the payslip is untouched. */
    public function test_marking_an_ordinary_comment_solved_changes_nothing_else(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $accountant = $this->makeUser('Accountant', 'note-solver@test.local');
        $this->actingAs($accountant);
        $this->reply($payslip, 'Asked the site supervisor to confirm the hours.');

        $reply = $payslip->refresh()->latestObjectionReply();

        $this->thread($payslip)
            ->callAction(TestAction::make('resolveComment')->table($reply));

        $this->assertNotNull($reply->refresh()->resolved_at);
        $this->assertSame($accountant->getKey(), $reply->resolved_by);

        // Still rejected: closing the objection is a decision about the payslip, not about a note on it.
        $this->assertSame(Payslip::REVIEW_REJECTED, $payslip->refresh()->employee_review);
    }

    /** The employee cannot mark anything solved — they hold no `CommentResolve`. */
    public function test_the_employee_cannot_mark_a_comment_solved(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');
        $this->reply($payslip, 'Any update?');

        $this->thread($payslip)
            ->assertActionHidden(TestAction::make('resolveComment')->table($payslip->refresh()->objectionComment));
    }

    // ──────────────────────────────────── correcting it instead ──

    /**
     * The other way to deal with an objection: change the payslip and ask again.
     *
     * Without this, correcting the figures left the review stuck on the old rejection — `recordEmployeeReview()`
     * refuses a second review — so the employee who was right about their own pay could never accept the
     * corrected version, and the only way to release the salary was to override a complaint that had already
     * been met.
     */
    public function test_a_corrected_payslip_can_be_sent_back_for_review(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->actingAs($this->makeUser('Accountant', 'correcting-accountant@test.local'));

        Livewire::test(ListPayslips::class)
            ->callTableAction('returnForReview', $payslip, ['note' => 'Overtime for the 14th added — net is now 96,400.'])
            ->assertHasNoTableActionErrors();

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_PENDING, $payslip->employee_review);
        $this->assertNull($payslip->employee_reviewed_at);
        $this->assertNull($payslip->employee_rejection_reason);
        $this->assertNull($payslip->review_objection_comment_id);
        $this->assertTrue($payslip->isPendingReview());
    }

    /** What changed is said in the thread, beside everything else that was said. */
    public function test_sending_it_back_posts_the_note_to_the_thread(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->actingAs($this->makeUser('Accountant', 'noting-accountant@test.local'));
        $payslip->refresh()->returnForReview('Overtime for the 14th added.');

        $this->assertSame(2, $payslip->comments()->count());
        $this->assertSame('Overtime for the 14th added.', $payslip->comments()->latest('id')->first()->body);

        // The objection itself is still there. Nothing said is unsaid by a correction.
        $this->assertSame('Overtime for the 14th is missing', $payslip->comments()->oldest('id')->first()->body);
    }

    /** And the employee is asked, in a message that says what changed. */
    public function test_the_employee_is_asked_to_look_again(): void
    {
        Notification::fake();

        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'asking-accountant@test.local'));
        $payslip->refresh()->returnForReview('Overtime added.');

        Notification::assertSentTo(
            $this->employee->user,
            PayslipReturnedForReview::class,
            fn (PayslipReturnedForReview $notification): bool => $notification->note === 'Overtime added.',
        );
    }

    /** They can then accept the corrected payslip, which they could not do before. */
    public function test_the_employee_can_accept_the_corrected_payslip(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $accountant = $this->makeUser('Accountant', 'reopening-accountant@test.local');
        $this->actingAs($accountant);
        $payslip->refresh()->returnForReview('Overtime added.');

        // Back as the employee, on the payslip they objected to.
        $this->actingAs($this->employee->user);

        Livewire::test(ListPayslips::class)
            ->callTableAction('acceptPayslip', $payslip->refresh());

        $this->assertSame(Payslip::REVIEW_ACCEPTED, $payslip->refresh()->employee_review);
    }

    /** It needs a note: "look at it again" with no reason is a second round trip. */
    public function test_sending_it_back_requires_saying_what_changed(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'silent-corrector@test.local'));

        Livewire::test(ListPayslips::class)
            ->callTableAction('returnForReview', $payslip, ['note' => ''])
            ->assertHasTableActionErrors(['note' => ['required']]);

        $this->assertSame(Payslip::REVIEW_REJECTED, $payslip->refresh()->employee_review);
    }

    /** It is offered on the payslip's own screens too, not only from the list. */
    public function test_sending_it_back_is_offered_on_the_payslip_pages(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'pages-accountant@test.local'));

        Livewire::test(ViewPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertActionVisible('returnForReview');

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertActionVisible('returnForReview');
    }

    /** A payslip nobody objected to is not sent back — there is nothing to send back. */
    public function test_a_pending_payslip_cannot_be_sent_back(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->makeUser('Accountant', 'nothing-accountant@test.local'));

        Livewire::test(ListPayslips::class)
            ->assertTableActionHidden('returnForReview', $payslip);
    }

    // ──────────────────────────────────────── the employee's way in ──

    /**
     * The employee can open their own payslip and read the thread on it.
     *
     * The gap this closes, and it made everything above theoretical for the person it is *for*: relation
     * managers live on a record page, the only record page was Edit, and Edit needs `PayslipUpdate` — which
     * an employee does not hold. So the comment thread was open to them by policy and unreachable by
     * routing. `ViewPayslip` is the page; `PayslipPolicy::view()` already allowed it.
     */
    public function test_the_employee_can_open_their_own_payslip(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        Livewire::test(ViewPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Employee objection')
            ->assertSee('Overtime for the 14th is missing');
    }

    /** The list offers them the way in, and does not offer the one they may not use. */
    public function test_the_list_offers_the_employee_view_and_not_edit(): void
    {
        $payslip = $this->payslip();

        Livewire::test(ListPayslips::class)
            ->assertTableActionVisible('view', $payslip)
            ->assertTableActionHidden('edit', $payslip);
    }

    /** And somebody else's payslip is still none of their business. */
    public function test_the_employee_cannot_open_another_persons_payslip(): void
    {
        $other = Employee::create([
            'user_id' => $this->makeUser('Employee', 'someone-else@test.local')->id,
            'employee_id' => 'EMP-OTHER',
            'gender' => 'Male',
            'phone' => '0300-1111111',
        ]);

        $theirs = Payslip::create([
            'employee_id' => $other->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'net_salary' => 50000,
        ]);

        $this->assertFalse(auth()->user()->can('view', $theirs));
    }

    /**
     * They can reply from that page, which is where the conversation actually happens for them.
     *
     * Filament makes relation managers read-only on a view page by default; `CommentsRelationManager`
     * overrides it, because what may be written here is `CommentPolicy`'s question and not the payslip's.
     */
    public function test_the_employee_can_reply_from_the_view_page(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        Livewire::test(CommentsRelationManager::class, [
            'ownerRecord' => $payslip->refresh(),
            'pageClass' => ViewPayslip::class,
        ])
            ->assertActionEnabled(TestAction::make('create')->table())
            ->callAction(TestAction::make('create')->table(), ['body' => 'It was the 21st as well.']);

        $this->assertSame(2, $payslip->comments()->count());
    }

    /**
     * And payroll can close it from the payslip itself, not only from the list.
     *
     * The list is where a month is triaged; this page is where the conversation is actually read, and asking
     * somebody to go back to the list to act on what they have just read is how a step gets skipped. Same
     * action, three placements — see `CloseObjectionAction`.
     */
    public function test_payroll_can_close_the_objection_from_the_payslip_page(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->actingAs($this->makeUser('Accountant', 'page-accountant@test.local'));
        $this->reply($payslip, 'Checked — the 14th was a public holiday.');

        Livewire::test(ViewPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertActionEnabled('overrideRejection')
            ->callAction('overrideRejection');

        $this->assertSame(Payslip::REVIEW_OVERRIDDEN, $payslip->refresh()->employee_review);
    }

    /** It is on the edit screen too, where the figures were just corrected. */
    public function test_the_edit_screen_offers_the_close_as_well(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->actingAs($this->makeUser('Accountant', 'edit-accountant@test.local'));
        $this->reply($payslip, 'Corrected — the hours are on this month now.');

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertActionEnabled('overrideRejection');
    }

    /** And the employee, on the same page, is not offered it at all. */
    public function test_the_employee_is_not_offered_the_close_on_their_own_payslip(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');
        $this->reply($payslip, 'Still waiting to hear back.');

        Livewire::test(ViewPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertActionHidden('overrideRejection');
    }

    // ───────────────────────────────────────────────── on the edit screen ──

    /** The edit screen states the objection, above the figures somebody is about to correct. */
    public function test_the_edit_screen_shows_the_objection(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->actingAs($this->makeUser('Accountant', 'editing-accountant@test.local'));

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertSee('Employee objection')
            ->assertSee('Overtime for the 14th is missing')
            ->assertSee('Nobody has replied yet');
    }

    /** And once answered, it states both sides and says the salary has been released. */
    public function test_the_edit_screen_shows_the_answer_too(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime for the 14th is missing');

        $this->actingAs($this->makeUser('Accountant', 'answered-accountant@test.local'));
        $this->reply($payslip, 'The 14th was a public holiday, already paid.');
        $payslip->refresh()->resolveObjection();

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertSee('Overtime for the 14th is missing')
            ->assertSee('Closed by Accountant')
            ->assertSee('released for payment');
    }

    /** A payslip nobody objected to shows no such block at all. */
    public function test_the_edit_screen_is_unchanged_without_an_objection(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->makeUser('Accountant', 'quiet-accountant@test.local'));

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertDontSee('Employee objection');
    }

    /** The Comments tab, mounted as it is on the payslip's edit page. */
    private function thread(Payslip $payslip): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CommentsRelationManager::class, [
            'ownerRecord' => $payslip->refresh(),
            'pageClass' => EditPayslip::class,
        ]);
    }

    /** A reply in the payslip's thread, from whoever is signed in. */
    private function reply(Payslip $payslip, string $body): void
    {
        $payslip->comments()->create(['user_id' => auth()->id(), 'body' => $body]);
    }

    private function payslip(): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'net_salary' => 50000,
        ]);
    }
}
