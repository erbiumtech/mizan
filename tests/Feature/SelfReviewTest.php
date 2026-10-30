<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Filament\Pages\MyReview;
use App\Modules\Performance\Filament\Resources\Reviews\Pages\EditReview;
use App\Modules\Performance\Filament\Resources\Reviews\RelationManagers\RatingsRelationManager;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Models\ReviewTemplate;
use App\Modules\Performance\Services\ReviewCycleService;
use App\Modules\Performance\Services\SelfReviews;
use App\Notifications\RecordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The employee's half of a review — docs/saas-launch-plan.md §14.6: "rated per competency by the employee
 * and the manager". Before this the employee could not reach their own review at all (it is hidden from
 * them until shared) and nothing ever recorded a self-review.
 */
class SelfReviewTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $managerLogin;

    private User $employeeLogin;

    private Employee $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->managerLogin = User::factory()->create(['status' => 1, 'name' => 'Sara Khan']);
        $this->employeeLogin = User::factory()->create(['status' => 1, 'name' => 'Bilal Ahmed']);
        // Filament sets a tenant for a signed-in user; nobody signed in is a state the panel never has.
        $this->actingAs(User::factory()->create(['status' => 1, 'name' => 'HR Admin']));
        $this->setCurrentTenant();

        foreach (['employees', 'performance'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->manager = Employee::create(['user_id' => $this->managerLogin->id, 'employee_id' => 'EMP-1', 'is_active' => true]);
        $this->employee = Employee::create(['user_id' => $this->employeeLogin->id, 'employee_id' => 'EMP-2', 'is_active' => true, 'manager_id' => $this->manager->id]);

        $template = ReviewTemplate::create(['name' => 'Sales', 'is_default' => true, 'is_active' => true]);
        $template->competencies()->create(['name' => 'Revenue', 'weight' => 60, 'sort' => 1]);
        $template->competencies()->create(['name' => 'Customer care', 'weight' => 40, 'sort' => 2]);

        Notification::fake();
    }

    private function openCycle(bool $selfReview = true): ReviewCycle
    {
        $cycle = ReviewCycle::create(['name' => 'H2', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31', 'includes_self_review' => $selfReview]);
        app(ReviewCycleService::class)->open($cycle);

        return $cycle->refresh();
    }

    private function reviewOf(Employee $employee, ReviewCycle $cycle): Review
    {
        return Review::where('employee_id', $employee->id)->where('review_cycle_id', $cycle->id)->firstOrFail();
    }

    public function test_a_cycle_asks_for_a_self_review_unless_told_not_to_and_existing_cycles_are_backfilled(): void
    {
        $this->assertTrue(ReviewCycle::create(['name' => 'A', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30'])->includes_self_review);

        // A row written without the column — every cycle that existed before it — reads as "yes".
        DB::table('review_cycles')->insert(['name' => 'Old', 'period_start' => '2025-01-01', 'period_end' => '2025-06-30', 'status' => 'closed']);
        $this->assertTrue(ReviewCycle::where('name', 'Old')->sole()->includes_self_review);
    }

    public function test_opening_a_cycle_tells_each_employee_their_self_review_is_open(): void
    {
        $this->openCycle();

        Notification::assertSentTo($this->employeeLogin, RecordChanged::class, fn (RecordChanged $n): bool => str_contains($n->title, 'self-review'));
    }

    public function test_the_employee_rates_each_competency_comments_and_submits_on_my_review(): void
    {
        $cycle = $this->openCycle();
        $review = $this->reviewOf($this->employee, $cycle);
        $this->actingAs($this->employeeLogin);

        $page = Livewire::test(MyReview::class)->assertOk()->assertCanSeeTableRecords($review->ratings);

        foreach ($review->ratings()->with('competency')->get() as $rating) {
            $page->callTableAction('rate', $rating, data: ['self_rating' => $rating->competency->name === 'Revenue' ? 5 : 3, 'self_note' => 'Because.'])
                ->assertHasNoTableActionErrors();
        }

        $page->callAction('comments', data: ['self_comments' => 'A strong half.'])->assertHasNoActionErrors();
        $page->callAction('submit')->assertHasNoActionErrors();

        $review->refresh();
        $this->assertNotNull($review->self_submitted_at);
        $this->assertSame(Review::STATUS_SELF_SUBMITTED, $review->status);
        $this->assertSame('A strong half.', $review->self_comments);
        $this->assertSame(4, $review->self_rating, 'weighted: 5×60% + 3×40% = 4.2');
        Notification::assertSentTo($this->managerLogin, RecordChanged::class, fn (RecordChanged $n): bool => str_contains($n->title, 'self-review is in'));

        // Submitted is final: nothing is waiting any more.
        $this->assertNull(Livewire::test(MyReview::class)->instance()->review());
    }

    public function test_submitting_needs_every_competency_rated(): void
    {
        $review = $this->reviewOf($this->employee, $this->openCycle());
        app(SelfReviews::class)->rate($review->ratings()->first(), $this->employeeLogin, 4, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate every competency first');

        app(SelfReviews::class)->submit($review, $this->employeeLogin);
    }

    public function test_nobody_writes_somebody_elses_self_review(): void
    {
        $review = $this->reviewOf($this->employee, $this->openCycle());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not yours');

        app(SelfReviews::class)->rate($review->ratings()->first(), $this->managerLogin, 1, 'mine now');
    }

    public function test_a_cycle_without_a_self_review_asks_the_employee_for_nothing(): void
    {
        $review = $this->reviewOf($this->employee, $this->openCycle(selfReview: false));
        $this->actingAs($this->employeeLogin);

        $this->assertFalse($review->awaitsSelfReview());
        $this->assertNull(Livewire::test(MyReview::class)->assertOk()->instance()->review());
        Notification::assertNotSentTo($this->employeeLogin, RecordChanged::class);
    }

    public function test_the_manager_sees_the_employees_rating_beside_theirs_and_cannot_overwrite_it(): void
    {
        $review = $this->reviewOf($this->employee, $this->openCycle());
        $rating = $review->ratings()->first();
        app(SelfReviews::class)->rate($rating, $this->employeeLogin, 5, 'Closed the big account.');

        Gate::before(fn () => true);
        $this->actingAs($this->managerLogin);

        Livewire::test(RatingsRelationManager::class, ['ownerRecord' => $review->fresh(), 'pageClass' => EditReview::class])
            ->assertSee('Closed the big account.')
            ->callTableAction('rate', $rating, data: ['self_rating' => 1, 'self_note' => 'overwritten', 'manager_rating' => 4, 'manager_note' => 'Agreed.'])
            ->assertHasNoTableActionErrors();

        $rating->refresh();
        $this->assertSame(5, $rating->self_rating);
        $this->assertSame('Closed the big account.', $rating->self_note);
        $this->assertSame(4, $rating->manager_rating);
    }

    /** Somebody with no login cannot open My Review, so the reviewer types in what they handed over. */
    public function test_for_somebody_without_a_login_the_reviewer_types_the_self_rating(): void
    {
        $driver = Employee::create(['employee_id' => 'EMP-3', 'name' => 'Kamran Ali', 'is_active' => true, 'manager_id' => $this->manager->id]);
        $review = $this->reviewOf($driver, $this->openCycle());
        $rating = $review->ratings()->first();

        Gate::before(fn () => true);
        $this->actingAs($this->managerLogin);

        Livewire::test(RatingsRelationManager::class, ['ownerRecord' => $review, 'pageClass' => EditReview::class])
            ->callTableAction('rate', $rating, data: ['self_rating' => 3, 'self_note' => 'From his paper form.', 'manager_rating' => 3])
            ->assertHasNoTableActionErrors();

        $this->assertSame(3, $rating->fresh()->self_rating);
    }

    public function test_the_managers_submission_closes_the_employees_half(): void
    {
        $review = $this->reviewOf($this->employee, $this->openCycle());
        $review->update(['status' => Review::STATUS_MANAGER_SUBMITTED, 'submitted_at' => now()]);

        $this->assertFalse($review->fresh()->awaitsSelfReview());

        $this->expectException(InvalidArgumentException::class);
        app(SelfReviews::class)->rate($review->ratings()->first(), $this->employeeLogin, 3, null);
    }
}
