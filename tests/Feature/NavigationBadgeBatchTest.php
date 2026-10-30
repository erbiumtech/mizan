<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Support\Models\Ticket;
use App\Support\NavigationBadge;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Twenty badges, one round trip — `App\Support\NavigationBadge`.
 *
 * `PanelPerformanceTest` measures the saving as a budget. What is asserted here is the two things a
 * budget cannot say: that the figures are still *right* after being fetched together, and that a
 * resource whose query throws takes its own badge down and nothing else.
 */
class NavigationBadgeBatchTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'badges@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'recruitment', 'support'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        Filament::setCurrentPanel('admin');
    }

    /** @return array<int, string> */
    private function queriesWhile(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $work();

        return $queries;
    }

    /**
     * The figures are the ones each resource would have got on its own.
     *
     * The risk a batch introduces is not that it is slow, it is that a subquery picks up the wrong
     * bindings and every badge on the page quietly shows somebody else's number. Two different
     * counts, fetched together, in one statement.
     */
    public function test_the_batch_returns_each_resource_its_own_figure(): void
    {
        $vacancy = Vacancy::create(['code' => 'V-1', 'title' => 'Developer', 'status' => Vacancy::STATUS_OPEN, 'openings' => 1]);

        foreach (['One', 'Two', 'Three'] as $name) {
            $applicant = Applicant::create(['name' => $name, 'email' => strtolower($name).'@test.local']);
            Application::create([
                'vacancy_id' => $vacancy->getKey(),
                'applicant_id' => $applicant->getKey(),
                'stage' => Application::STAGE_SCREENING,
                'applied_on' => now()->toDateString(),
            ]);
        }

        Ticket::create(['subject' => 'Only one of these', 'status' => Ticket::STATUS_OPEN]);

        $applications = \App\Modules\Recruitment\Filament\Resources\Applications\ApplicationResource::class;
        $tickets = \App\Modules\Support\Filament\Resources\Tickets\TicketResource::class;

        $this->assertSame('3', NavigationBadge::of($applications), 'three open applications');
        $this->assertSame('1', NavigationBadge::of($tickets), 'and exactly one open ticket');
    }

    /**
     * The whole band in one statement, which is the point of the exercise.
     *
     * Asserted as "one statement carries several counts" rather than by a total: the number of
     * badges changes every time a module is added, and a test that pins it would be re-pinned by
     * every phase rather than catching anything.
     */
    public function test_the_counts_travel_together(): void
    {
        $queries = $this->queriesWhile(function (): void {
            NavigationBadge::of(\App\Modules\Leave\Filament\Resources\LeaveRequests\LeaveRequestResource::class);
        });

        $batched = array_filter($queries, fn (string $sql): bool => substr_count($sql, 'count(*)') > 1);

        $this->assertNotEmpty($batched, 'the badges did not go to the database together');

        $counts = array_sum(array_map(fn (string $sql): int => substr_count($sql, 'count(*)'), $queries));
        $statements = count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'count(*)')));

        $this->assertGreaterThan($statements, $counts,
            'more counts than statements carrying them is the whole saving');
    }

    /**
     * Asking twice in one request asks the database once.
     *
     * Filament evaluates a badge while building the navigation and again on a Livewire update; the
     * memo is what stops the second one costing anything.
     */
    public function test_a_second_read_in_the_same_request_runs_nothing(): void
    {
        $resource = \App\Modules\Support\Filament\Resources\Tickets\TicketResource::class;

        NavigationBadge::of($resource);

        $queries = $this->queriesWhile(fn () => NavigationBadge::of($resource));

        $this->assertSame([], array_filter($queries, fn (string $sql): bool => str_contains($sql, 'count(*)')),
            'the second read went back to the database');
    }

    /**
     * A resource that cannot answer loses its own badge and nobody else's.
     *
     * The sidebar is drawn on every page of the panel. A navigation that fails to render because one
     * module's scope threw is a worse outcome than a number that is missing, which is why every step
     * of the batch is wrapped — and why that leniency needs a test, since it is invisible when it
     * works.
     */
    public function test_one_broken_badge_does_not_take_the_others_with_it(): void
    {
        BrokenBadgeResource::$shouldThrow = true;

        // Before the first read, because the first read is what fills the batch — a row created
        // after it would be answered from a cache that had already counted zero.
        Ticket::create(['subject' => 'Still counted', 'status' => Ticket::STATUS_OPEN]);

        $this->assertNull(NavigationBadge::of(BrokenBadgeResource::class), 'it has no number to show');

        $this->assertSame(
            '1',
            NavigationBadge::of(\App\Modules\Support\Filament\Resources\Tickets\TicketResource::class),
            'and the rest of the sidebar is unaffected',
        );
    }
}

/** A resource whose badge query throws, for the test above. Not registered on any panel. */
class BrokenBadgeResource
{
    public static bool $shouldThrow = false;

    public static function canAccess(): bool
    {
        return true;
    }

    public static function badgeQuery(): mixed
    {
        if (static::$shouldThrow) {
            throw new \RuntimeException('this resource cannot count itself');
        }

        return null;
    }
}
