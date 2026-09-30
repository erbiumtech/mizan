<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Widgets\HealthOverview;
use App\Modules\Core\Models\User;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Tests\AccountingTestCase;

/**
 * The dashboard health widget: super-admin-only, and reads the stored results the
 * scheduled check writes. The security invariant is the point — installation-wide
 * health must not reach a company administrator, the same line /ops/health draws.
 */
class HealthOverviewWidgetTest extends AccountingTestCase
{
    private function store(string $status, string $label, string $batch, ?string $createdAt = null): void
    {
        HealthCheckResultHistoryItem::create([
            'check_name' => str($label)->camel()->value(),
            'check_label' => $label,
            'status' => $status,
            'short_summary' => $status,
            'meta' => [],
            'ended_at' => now(),
            'batch' => $batch,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }

    public function test_only_a_super_admin_sees_it(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->assertTrue(HealthOverview::canView());

        $this->actingAs(User::factory()->create(['is_super_admin' => false]));
        $this->assertFalse(HealthOverview::canView());
    }

    public function test_it_reports_failing_and_warning_checks(): void
    {
        $batch = (string) str()->uuid();
        $this->store('ok', 'Backups', $batch);
        $this->store('warning', 'Disk Space', $batch);
        $this->store('failed', 'Horizon', $batch);

        $stats = $this->invade(new HealthOverview)->getStats();

        // Overall: a failing check dominates.
        $this->assertStringContainsString('1 failing', $stats[0]->getValue());
        $this->assertSame('1 of 3 checks passing', $stats[0]->getDescription());
        // The two not-ok checks are named.
        $this->assertStringContainsString('Disk Space', $stats[1]->getValue());
        $this->assertStringContainsString('Horizon', $stats[1]->getValue());
    }

    public function test_all_green_reads_as_all_ok(): void
    {
        $batch = (string) str()->uuid();
        $this->store('ok', 'Backups', $batch);
        $this->store('ok', 'Disk Space', $batch);

        $stats = $this->invade(new HealthOverview)->getStats();

        $this->assertSame('All systems OK', $stats[0]->getValue());
        $this->assertSame('Nothing', $stats[1]->getValue());
    }

    public function test_a_stale_last_run_is_flagged_however_green(): void
    {
        $batch = (string) str()->uuid();
        // A passing check, but written half an hour ago — the schedule has stopped.
        $this->store('ok', 'Backups', $batch, now()->subMinutes(30)->toDateTimeString());

        $stats = $this->invade(new HealthOverview)->getStats();

        $this->assertSame('Stale', $stats[0]->getValue());
    }

    public function test_no_results_yet_says_so_rather_than_all_ok(): void
    {
        $stats = $this->invade(new HealthOverview)->getStats();

        $this->assertCount(1, $stats);
        $this->assertSame('Not yet checked', $stats[0]->getValue());
    }

    /** getStats() is protected; reach it the way Filament does. */
    private function invade(HealthOverview $widget): object
    {
        return new class($widget)
        {
            public function __construct(private HealthOverview $w) {}

            public function getStats(): array
            {
                return (fn () => $this->getStats())->call($this->w);
            }
        };
    }
}
