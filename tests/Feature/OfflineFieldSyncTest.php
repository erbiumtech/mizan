<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogEvent;
use App\Modules\ConstructionField\Models\DailyLogPhoto;
use App\Modules\ConstructionField\Models\PunchInspection;
use App\Modules\ConstructionField\Models\PunchItem;
use App\Modules\ConstructionField\Models\PunchList;
use App\Modules\ConstructionField\Services\DailyLogService;
use App\Modules\ConstructionField\Services\PunchListService;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Site work offline — docs/desktop-mobile-plan.md O2, on the engine O1 built.
 *
 * What O2 adds, asserted on the two shapes the plan names. **Diary lines are append-only**: they land on
 * the day through DailyLogService whatever order they arrive in, a replay changes nothing, a signed-off day
 * refuses them and the refusal is kept. **A snag is the one mutable document**: a change made against the
 * item's current `version` is applied through PunchListService; one made against an older version keeps
 * the server's value, files the device's change on the item for a person, and is answered `stale`. And
 * **photographs ride behind their line**: uploaded once the line has landed, idempotent on their own id,
 * capped in size, refused when they name a command that was never applied. Every read is the panel's own
 * permission, and a company without the module has no endpoint at all.
 */
class OfflineFieldSyncTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $foreman;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 20:00:00');

        // actingAsApiUser() makes the company the foreman joins, but signs in through Sanctum only.
        // Filament::setTenant() announces TenantSet with the panel's web-guard user and type-errors on
        // null, so the foreman is put on that guard too, without making it the default: the API calls
        // below still authenticate through Sanctum, as a device's would.
        $this->foreman = $this->actingAsApiUser('Administrator', 'foreman@test.local');
        $this->app['auth']->guard('web')->setUser($this->foreman);
        $this->setCurrentTenant($this->apiCompany);
        $this->license(true);
        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
    }

    private function license(bool $licensed): void
    {
        foreach (['construction' => true, 'construction_field' => $licensed] as $module => $on) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => $on, 'enabled' => $on],
            );
        }
        modules()->flush();
    }

    /** @return array{id: string, payload: array<string, mixed>, captured_at: string} */
    private function line(string $at, array $extra = []): array
    {
        // array_replace, not `+`: a union keeps the left-hand key, so `['job_id' => 99999]` was ignored and
        // the "job elsewhere" lines were written to this job and applied.
        return [
            'id' => (string) Str::ulid(),
            'payload' => array_replace([
                'type' => 'entry',
                'job_id' => $this->job->getKey(),
                'log_date' => substr($at, 0, 10),
                'kind' => 'delay',
                'description' => 'Concrete pump late',
                'started_at' => substr($at, 11, 5),
            ], $extra),
            'captured_at' => $at,
        ];
    }

    /** @return array{id: string, payload: array<string, mixed>, captured_at: string} */
    private function change(PunchItem $item, int $baseVersion, string $action, array $extra = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'payload' => ['punch_item_id' => $item->getKey(), 'base_version' => $baseVersion, 'action' => $action] + $extra,
            'captured_at' => '2026-10-28T14:10:00Z',
        ];
    }

    private function diary(array $commands)
    {
        return $this->postJson('/api/v1/sync/site-diary', ['commands' => $commands]);
    }

    private function snags(array $commands)
    {
        return $this->postJson('/api/v1/sync/punch-items', ['commands' => $commands]);
    }

    private function photo(string $commandId, ?string $id = null, ?UploadedFile $file = null)
    {
        return $this->post('/api/v1/sync/site-diary/blobs', [
            'id' => $id ?? (string) Str::ulid(),
            'command_id' => $commandId,
            'captured_at' => '2026-10-28T09:20:00Z',
            'caption' => 'Pump arriving at 11:40',
            'subject' => 'progress',
            'file' => $file ?? UploadedFile::fake()->image('pump.jpg', 640, 480),
        ], ['Accept' => 'application/json']);
    }

    private function item(): PunchItem
    {
        $punch = app(PunchListService::class);
        $list = $punch->openList($this->job, ['name' => 'Level 4 walk', 'kind' => PunchList::KIND_PRE_HANDOVER]);

        return $punch->addItem($list, ['description' => 'Sealant missing at window head']);
    }

    // ------------------------------------------------------------------ the diary: append-only

    public function test_lines_land_on_the_day_whatever_order_they_arrive_in_and_a_replay_changes_nothing(): void
    {
        // The evening's line is in the outbox ahead of the morning's.
        $evening = $this->line('2026-10-28T17:30:00Z', ['kind' => 'visitor', 'description' => 'Client walk-round']);
        $morning = $this->line('2026-10-28T09:15:00Z');

        $this->diary([$evening, $morning])
            ->assertOk()
            ->assertJsonPath('workflow', 'site-diary')
            ->assertJsonPath('summary.applied', 2)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied')
            // The day as the device should hold it: both lines, in the order they happened.
            ->assertJsonPath('results.1.result.daily_log.log_date', '2026-10-28')
            ->assertJsonPath('results.1.result.daily_log.events.0.started_at', '09:15')
            ->assertJsonPath('results.1.result.daily_log.events.1.started_at', '17:30');

        // One diary for the day — open() was asked once, then the day was found.
        $this->assertSame(1, DailyLog::count());
        $this->assertSame(2, DailyLogEvent::count());

        // The device's clock is recorded, never the order: received in batch order, captured as it was.
        $rows = SyncCommand::where('workflow', 'site-diary')->orderBy('id')->get();
        $this->assertSame(['17:30', '09:15'], $rows->map(fn (SyncCommand $c) => $c->captured_at->format('H:i'))->all());

        // The connection dropped before the device heard: the whole batch again, nothing applied twice.
        $this->diary([$evening, $morning])
            ->assertOk()
            ->assertJsonPath('summary.replayed', 2)
            ->assertJsonPath('summary.applied', 0);

        $this->assertSame(2, DailyLogEvent::count());
    }

    public function test_a_batch_cut_short_resumes_and_a_signed_off_day_refuses_and_is_kept(): void
    {
        $a = $this->line('2026-10-28T09:15:00Z');
        $b = $this->line('2026-10-28T10:00:00Z', ['kind' => 'instruction', 'description' => 'Engineer asked for extra ties']);

        // The connection died after the first line was applied.
        $this->diary([$a])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->diary([$a, $b])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'replayed')
            ->assertJsonPath('results.1.status', 'applied');

        // The site manager signs the day off; a line the foreman wrote offline arrives afterwards.
        app(DailyLogService::class)->approve(DailyLog::firstOrFail());
        $late = $this->line('2026-10-28T16:00:00Z');

        $this->diary([$late])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.http_status', 422)
            ->assertJsonPath('results.0.result.message', fn (string $m) => str_contains($m, 'approved') || str_contains($m, 'signed') || str_contains($m, 'locked'));

        // Kept, so the device is told the same thing again and stops resending.
        $this->diary([$late])->assertOk()->assertJsonPath('results.0.status', 'replayed')->assertJsonPath('results.0.http_status', 422);
        $this->assertSame(2, DailyLogEvent::count());
    }

    public function test_a_line_for_a_job_elsewhere_or_without_the_right_is_refused_not_failed(): void
    {
        $this->diary([$this->line('2026-10-28T09:15:00Z', ['job_id' => 99999])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.result.message', 'That job is not in this company.');

        $nobody = User::create(['name' => 'Visitor', 'email' => 'visitor@test.local', 'password' => bcrypt('x'), 'status' => 1]);
        $nobody->companies()->syncWithoutDetaching([$this->tenant->getKey()]);
        Sanctum::actingAs($nobody, ['api']);

        $this->diary([$this->line('2026-10-28T09:15:00Z')])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.result.message', 'You may not write the site diary in this company.');

        $this->getJson('/api/v1/sync/site-diary/changes')->assertForbidden();
        $this->getJson('/api/v1/sync/punch-items/changes')->assertForbidden();
        $this->assertSame(0, DailyLog::count());
    }

    // ------------------------------------------------------------------ a snag: the one mutable document

    public function test_a_change_made_against_the_current_version_is_applied_through_the_service(): void
    {
        $item = $this->item();
        $this->assertSame(0, $item->version);

        $this->snags([$this->change($item, 0, 'ready')])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.result.item.status', PunchItem::STATUS_READY)
            ->assertJsonPath('results.0.result.item.version', 1);

        // Closing is still only an inspection: a pass, against the version the device now holds.
        $this->snags([$this->change($item, 1, 'inspect', ['result' => PunchInspection::RESULT_PASSED])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.result.item.status', PunchItem::STATUS_CLOSED);

        $this->assertSame(1, PunchInspection::count());

        // An inspection with no result is the service's refusal, kept as one.
        $other = $this->item();
        $this->snags([$this->change($other, 0, 'inspect')])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.result.message', fn (string $m) => str_contains($m, 'needs a result'));
    }

    public function test_a_stale_change_keeps_the_server_value_and_files_the_device_change_for_a_person(): void
    {
        $item = $this->item();

        // The foreman's phone read the item at version 0 and went dark. Meanwhile the office failed it at
        // re-inspection — which is a save, so the item is at a later version now.
        app(PunchListService::class)->readyForInspection($item);
        app(PunchListService::class)->inspect($item->refresh(), ['result' => PunchInspection::RESULT_FAILED]);
        $item->refresh();
        $this->assertSame(PunchItem::STATUS_IN_PROGRESS, $item->status);
        $atServer = $item->version;
        $this->assertGreaterThan(0, $atServer);

        // The phone's change arrives: "passed", made against version 0.
        $stale = $this->change($item, 0, 'inspect', ['result' => PunchInspection::RESULT_PASSED, 'notes' => 'looks fine']);

        $this->snags([$stale])
            ->assertOk()
            ->assertJsonPath('summary.stale', 1)
            ->assertJsonPath('results.0.status', 'stale')
            ->assertJsonPath('results.0.http_status', 409)
            ->assertJsonPath('results.0.result.message', fn (string $m) => str_contains($m, 'was not applied'))
            // The device is handed the item as the server holds it, to replace its copy with.
            ->assertJsonPath('results.0.result.item.status', PunchItem::STATUS_IN_PROGRESS);

        // The server's value stands: not closed, no second inspection.
        $item->refresh();
        $this->assertSame(PunchItem::STATUS_IN_PROGRESS, $item->status);
        $this->assertSame(1, PunchInspection::count());

        // The device's change is on the item for a person, with who, what and both versions.
        $this->assertStringContainsString('Offline change not applied', $item->notes);
        $this->assertStringContainsString('Passed', $item->notes);
        $this->assertStringContainsString('version 0', $item->notes);
        $this->assertStringContainsString("version {$atServer}", $item->notes);

        // And the activity log records the note like any other change to the item.
        $this->assertTrue(ActivityLog::query()
            ->where('subject_type', $item->getMorphClass())
            ->where('subject_id', $item->getKey())
            ->where('event', 'updated')
            ->get()
            ->contains(fn (ActivityLog $entry): bool => str_contains((string) data_get($entry->properties, 'attributes.notes'), 'Offline change not applied')));

        // Recorded as stale, so a replay says the same and files nothing twice.
        $this->assertSame(SyncCommand::STATUS_STALE, SyncCommand::where('client_id', $stale['id'])->value('status'));
        $notes = $item->notes;

        $this->snags([$stale])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'replayed')
            ->assertJsonPath('results.0.http_status', 409);

        $this->assertSame($notes, $item->refresh()->notes);
    }

    // ------------------------------------------------------------------ photographs ride behind their line

    public function test_a_photograph_rides_behind_its_line_and_is_stored_once(): void
    {
        $line = $this->line('2026-10-28T09:15:00Z');
        $eventId = $this->diary([$line])->assertOk()->json('results.0.result.event_id');

        $disk = Storage::disk('public');
        $before = count($disk->allFiles('construction/site-photos'));
        $blobId = (string) Str::ulid();

        $this->photo($line['id'], $blobId)
            ->assertOk()
            ->assertJsonPath('workflow', 'site-diary')
            ->assertJsonPath('result.status', 'applied')
            ->assertJsonPath('result.http_status', 200)
            ->assertJsonPath('result.result.photo.event_id', $eventId)
            ->assertJsonPath('result.result.photo.caption', 'Pump arriving at 11:40');

        $photo = DailyLogPhoto::firstOrFail();
        $this->assertSame((int) $eventId, (int) $photo->event_id);
        $this->assertSame(DailyLog::firstOrFail()->getKey(), $photo->daily_log_id);
        $this->assertTrue($disk->exists($photo->file_path), 'The photograph is on the company disk where the Photos tab looks.');
        $this->assertStringStartsWith('construction/site-photos/', $photo->file_path);
        $this->assertNotEmpty($photo->file_hash);

        // The answer was lost; the same file again is answered from its record and stored once.
        $this->photo($line['id'], $blobId)->assertOk()->assertJsonPath('result.status', 'replayed');

        $this->assertSame(1, DailyLogPhoto::count());
        $this->assertSame($before + 1, count($disk->allFiles('construction/site-photos')));

        // And the day the device reads now carries it.
        $this->getJson('/api/v1/sync/site-diary/changes')
            ->assertOk()
            ->assertJsonPath('data.0.photos.0.id', $photo->getKey());
    }

    public function test_a_photograph_over_the_cap_or_for_a_command_never_applied_is_refused_and_leaves_nothing(): void
    {
        $disk = Storage::disk('public');
        $before = count($disk->allFiles('construction/site-photos'));

        // Over the Photos tab's own 12 MB: a validation error, never stored, never recorded.
        $line = $this->line('2026-10-28T09:15:00Z');
        $this->diary([$line])->assertOk();

        $this->photo($line['id'], null, UploadedFile::fake()->image('huge.jpg')->size(13000))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // Naming a command this device never sent.
        $orphan = (string) Str::ulid();
        $this->photo((string) Str::ulid(), $orphan)
            ->assertOk()
            ->assertJsonPath('result.status', 'rejected')
            ->assertJsonPath('result.http_status', 422)
            ->assertJsonPath('result.result.message', fn (string $m) => str_contains($m, 'never sent'));

        // Behind a line the server refused: there is nothing to attach it to.
        $refused = $this->line('2026-10-28T09:15:00Z', ['job_id' => 99999]);
        $this->diary([$refused])->assertOk()->assertJsonPath('results.0.status', 'rejected');

        $this->photo($refused['id'])
            ->assertOk()
            ->assertJsonPath('result.status', 'rejected')
            ->assertJsonPath('result.result.message', fn (string $m) => str_contains($m, 'not recorded'));

        $this->assertSame(0, DailyLogPhoto::count());
        $this->assertSame($before, count($disk->allFiles('construction/site-photos')), 'A refused photograph leaves no file behind.');

        // The refusal is recorded, so the device is told the same thing again.
        $this->assertSame(SyncCommand::STATUS_REJECTED, SyncCommand::where('client_id', strtoupper($orphan))->value('status'));
    }

    // ------------------------------------------------------------------ the reads

    public function test_the_diary_read_pages_whole_days_and_a_new_line_brings_a_day_round_again(): void
    {
        Carbon::setTestNow('2026-10-26 18:00:00');
        $monday = app(DailyLogService::class)->open($this->job, '2026-10-26');
        Carbon::setTestNow('2026-10-27 18:00:00');
        $tuesday = app(DailyLogService::class)->open($this->job, '2026-10-27');

        $first = $this->getJson('/api/v1/sync/site-diary/changes?limit=1')
            ->assertOk()
            ->assertJsonPath('workflow', 'site-diary')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $monday->getKey())
            ->assertJsonPath('deleted', [])
            ->assertJsonPath('has_more', true);

        $second = $this->getJson('/api/v1/sync/site-diary/changes?since='.urlencode($first->json('cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tuesday->getKey())
            ->assertJsonPath('has_more', false);

        // A line added to Monday moves Monday — the device is handed the whole day again, with the line.
        Carbon::setTestNow('2026-10-28 09:00:00');
        app(DailyLogService::class)->addEvent($monday, ['kind' => 'visitor', 'description' => 'Building control']);

        $this->getJson('/api/v1/sync/site-diary/changes?since='.urlencode($second->json('cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $monday->getKey())
            ->assertJsonPath('data.0.events.0.description', 'Building control');
    }

    public function test_the_snag_read_carries_each_version_and_names_the_ones_deleted_since(): void
    {
        Carbon::setTestNow('2026-10-28 08:00:00');
        $keep = $this->item();
        $gone = $this->item();

        $page = $this->getJson('/api/v1/sync/punch-items/changes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 0)
            // Nothing to delete on a read from the start.
            ->assertJsonPath('deleted', []);

        // Never inspected, so it may be deleted outright; the activity log is the only trace it leaves.
        Carbon::setTestNow('2026-10-28 09:00:00');
        $gone->delete();
        app(PunchListService::class)->readyForInspection($keep);

        $this->getJson('/api/v1/sync/punch-items/changes?since='.urlencode($page->json('cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $keep->getKey())
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('deleted', [$gone->getKey()]);
    }

    // ------------------------------------------------------------------ the gate

    public function test_a_company_without_site_operations_has_no_endpoint_for_it(): void
    {
        $line = $this->line('2026-10-28T09:15:00Z');
        $this->license(false);

        $this->diary([$line])->assertForbidden();
        $this->photo($line['id'])->assertForbidden();
        $this->getJson('/api/v1/sync/site-diary/changes')->assertForbidden();
        $this->snags([['id' => (string) Str::ulid(), 'payload' => ['punch_item_id' => 1, 'base_version' => 0, 'action' => 'ready']]])->assertForbidden();
        $this->getJson('/api/v1/sync/punch-items/changes')->assertForbidden();

        $this->assertSame(0, SyncCommand::count());
    }

    /**
     * The jobs a diary line may name come from their own endpoint, fetched by the outbox after a page has
     * loaded, not rendered into every panel page (PanelPerformanceTest's query budget caught that).
     */
    public function test_the_job_list_is_its_own_endpoint_for_whoever_may_write_the_diary(): void
    {
        $this->actingAs($this->foreman, 'web');

        $this->getJson('/'.$this->tenant->slug.'/sync/site-diary/jobs')
            ->assertOk()
            ->assertJsonPath('jobs.0.id', $this->job->getKey())
            ->assertJsonPath('jobs.0.label', 'J-1 — Tower');

        // The outbox script the panel links to is versioned by content and cached for good.
        $this->get(\App\Http\Controllers\PwaController::outboxUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertSee('MizanSync', false);
    }

    public function test_the_panel_replays_through_the_session_routes(): void
    {
        // The panel's session, not a token: the offline page has nothing else.
        $this->actingAs($this->foreman, 'web');
        $line = $this->line('2026-10-28T09:15:00Z');

        $this->postJson('/'.$this->tenant->slug.'/sync/site-diary', ['commands' => [$line]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        $this->post('/'.$this->tenant->slug.'/sync/site-diary/blobs', [
            'id' => (string) Str::ulid(),
            'command_id' => $line['id'],
            'caption' => 'Pump',
            'file' => UploadedFile::fake()->image('pump.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('result.status', 'applied');

        $this->assertSame(1, DailyLogPhoto::count());
    }

    public function test_the_inbox_is_on_the_versioned_surface_only(): void
    {
        $this->postJson('/api/sync/site-diary', ['commands' => [$this->line('2026-10-28T09:15:00Z')]])->assertNotFound();
        $this->postJson('/api/sync/punch-items', ['commands' => []])->assertNotFound();
    }
}
