<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Pages\DocumentsExpiring;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Services\DocumentExpiryCheck;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Documents Expiring — `docs/reports-expansion-plan.md` Phase 1.5.
 *
 * `LifecycleTest` owns the reminder machinery: warning once per threshold crossed, going quiet in between,
 * warning again after a renewal. None of that is re-asserted here.
 *
 * What this file is about is the one decision that separates a report from a reminder, and it is the reason
 * the plan's own instruction — "`DocumentExpiryCheck::due()` as a table" — could not be followed literally.
 * `due()` suppresses a document once it has been warned about. A report built on it would have shown fewer
 * documents the more reliably the emails went out: emptiest on the company that had been most diligent, and
 * silent about why. So the report reads `expiring()`, and the first test below is the one that would have
 * caught the mistake.
 */
class LifecycleReportsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2026-08-20';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['lifecycle', 'employees'] as $module) {
            $this->setModule($module, true);
        }

        $this->employee = $this->makeEmployee('EMP-1');
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function makeEmployee(string $id): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'name' => $id,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
    }

    private function document(array $attributes = []): EmployeeDocument
    {
        return EmployeeDocument::create(array_merge([
            'employee_id' => $this->employee->id,
            'kind' => 'visa',
            'number' => 'V-12345',
            'expires_on' => '2026-09-10',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('DocumentsExpiring', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for DocumentsExpiring');

        return $payload;
    }

    private function cells(array $payload): string
    {
        return collect($payload['rows'])->flatten()->implode(' | ');
    }

    // ───────────────────────────────────────────────────────── the listing ──

    /**
     * A document already warned about is still on the report.
     *
     * The whole point. `due()` would have dropped this row — correctly, because a daily mail that repeated
     * itself for thirty days would train somebody to filter it — and a report that dropped it would tell
     * the company nothing lapses soon on the very day something does.
     */
    public function test_a_document_already_warned_about_is_still_listed(): void
    {
        // Twenty-one days out, so the threshold it has crossed is 30 — and it has already been warned at
        // 30, which is what silences `due()`. Warned at 60 it would legitimately warn again, which is the
        // reminder machinery working and not the case under test here.
        $this->document(['expiry_notified_at_days' => 30]);

        $this->assertCount(
            0,
            app(DocumentExpiryCheck::class)->due(self::AS_OF),
            'the reminder query should be silent — that is what makes this test worth having',
        );

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString('V-12345', $this->cells($payload));
    }

    /** Expired documents are listed, and counted separately: a deadline passed is a different problem. */
    public function test_an_expired_document_is_listed_and_counted_on_its_own(): void
    {
        $this->document(['expires_on' => '2026-07-01', 'number' => 'OLD-1']);
        $this->document(['expires_on' => '2026-09-10', 'number' => 'SOON-1']);

        $payload = $this->report();

        $this->assertSame(2.0, $payload['tiles'][0]['value']);
        $this->assertSame(1.0, $payload['tiles'][1]['value'], 'the already-expired tile');
        $this->assertStringContainsString('1 OF 2 HAVE ALREADY EXPIRED', $payload['note']);
    }

    /**
     * Expired days read as an overdue count, not a negative countdown.
     *
     * `-50` and `50 ago` are the same figure and opposite readings, and the first invites being read as
     * the second's opposite — fifty days left rather than fifty days past.
     */
    public function test_an_expired_document_states_how_long_ago_rather_than_a_negative(): void
    {
        $this->document(['expires_on' => '2026-07-01']);

        $payload = $this->report();

        $this->assertSame('50 ago', $payload['rows'][0][4]);
        $this->assertStringNotContainsString('-50', $this->cells($payload));
    }

    /** Soonest first, so the top of the list is the most urgent — and expired sits above everything. */
    public function test_the_list_is_ordered_by_urgency(): void
    {
        $this->document(['expires_on' => '2026-10-10', 'number' => 'LAST']);
        $this->document(['expires_on' => '2026-08-25', 'number' => 'MIDDLE']);
        $this->document(['expires_on' => '2026-07-01', 'number' => 'FIRST']);

        $numbers = array_column($this->report()['rows'], 2);

        $this->assertSame(['FIRST', 'MIDDLE', 'LAST'], $numbers);
    }

    /**
     * The window is the widest configured reminder threshold.
     *
     * So the report covers exactly the population the daily mail watches. A company that widens its
     * thresholds widens both at once, rather than owning a report that disagrees with its own email.
     */
    public function test_the_window_follows_the_configured_reminder_thresholds(): void
    {
        // 100 days out: outside the default 60 and inside a 120.
        $this->document(['expires_on' => '2026-11-28', 'number' => 'FAR']);

        $this->assertCount(0, $this->report()['rows']);

        config(['lifecycle.document_expiry_thresholds' => [120, 30, 7]]);

        $payload = $this->report();
        $this->assertCount(1, $payload['rows']);
        // And the band is named in the same numbers, rather than a fixed set of words.
        $this->assertSame('Within 120 days', $payload['rows'][0][5]);
    }

    /** A document with no expiry recorded expires never, and is not on a list of expiries. */
    public function test_a_document_with_no_expiry_is_not_listed(): void
    {
        $this->document(['kind' => 'degree', 'expires_on' => null, 'number' => 'DEG-1']);

        $this->assertCount(0, $this->report()['rows']);
    }

    /** The kind is read by a person, not a machine: "cnic" is a column value. */
    public function test_the_document_kind_is_stated_in_words(): void
    {
        $this->document(['kind' => 'cnic']);

        $payload = $this->report();

        $this->assertSame('Cnic', $payload['rows'][0][1]);
        $this->assertStringNotContainsString('cnic', $this->cells($payload));
    }

    /** A missing number is stated. A blank cell in a compliance list reads as a fault in the report. */
    public function test_a_missing_document_number_is_stated_rather_than_blank(): void
    {
        $this->document(['number' => null]);

        $this->assertSame('Not recorded', $this->report()['rows'][0][2]);
    }

    /** Nothing lapsing is a sentence, not an empty grid. */
    public function test_it_says_when_nothing_lapses(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOTHING LAPSES INSIDE THE REMINDER WINDOW', $payload['note']);
    }

    /** Everybody's documents, named by employee. */
    public function test_it_names_the_employee_each_document_belongs_to(): void
    {
        $this->document();
        $other = $this->makeEmployee('EMP-2');
        $this->document(['employee_id' => $other->id, 'number' => 'V-99999']);

        $cells = $this->cells($this->report());

        $this->assertStringContainsString('EMP-1', $cells);
        $this->assertStringContainsString('EMP-2', $cells);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->document();

        $onThePage = Livewire::test(DocumentsExpiring::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('DocumentsExpiring', self::AS_OF, false, []));
    }

    // ─────────────────────────────────────────────────────────────── gating ──

    public function test_the_report_is_gated_on_the_lifecycle_module(): void
    {
        Gate::before(fn () => true);

        $this->assertTrue(DocumentsExpiring::canAccess());

        $this->setModule('lifecycle', false);

        $this->assertFalse(DocumentsExpiring::canAccess());
    }

    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(DocumentsExpiring::canAccess());
    }
}
