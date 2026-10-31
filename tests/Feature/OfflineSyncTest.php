<?php

namespace Tests\Feature;

use App\Models\TenantModel;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Support\Sync\SyncChanges;
use App\Support\Sync\SyncInbox;
use App\Support\Sync\SyncWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The offline sync engine on its first workflow — docs/desktop-mobile-plan.md §5a, O1.
 *
 * What the six rules promise, tested on attendance punches: a batch is applied in the order it arrives
 * through the same recorder the online clock-in uses; a command that arrives twice answers from its row
 * and changes nothing; punches that arrive in the wrong order still come out with the earlier moment as
 * the check-in; a batch cut short resumes from where it stopped; a refusal is kept and not re-asked; the
 * read pages by a cursor and shows the caller only what they may see; and a company without the module
 * has no inbox at all.
 */
class OfflineSyncTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 20:00:00');

        $this->user = User::factory()->create(['status' => 1]);
        $this->actingAs($this->user);
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->user->getKey(),
            'employee_id' => 'EMP-1',
            'name' => 'Ayesha',
            'gender' => 'Female',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        $this->license(true);
    }

    private function license(bool $licensed): void
    {
        foreach (['attendance', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => $module === 'employees' || $licensed, 'enabled' => $module === 'employees' || $licensed],
            );
        }
        modules()->flush();
    }

    /** @return array{id: string, payload: array<string, mixed>, captured_at: string} */
    private function punch(string $at, array $extra = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'payload' => ['type' => 'punch', 'punched_at' => $at] + $extra,
            'captured_at' => $at,
        ];
    }

    private function sync(array $commands)
    {
        return $this->postJson('/api/v1/sync/attendance', ['commands' => $commands]);
    }

    public function test_a_batch_of_punches_is_applied_in_order_through_the_recorder(): void
    {
        $in = $this->punch('2026-10-28T09:07:00Z', ['latitude' => 31.5204, 'longitude' => 74.3587, 'accuracy_m' => 12]);
        $out = $this->punch('2026-10-28T18:02:00Z');

        $response = $this->sync([$in, $out])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('workflow', 'attendance')
            ->assertJsonPath('summary.applied', 2)
            ->assertJsonPath('results.0.id', $in['id'])
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.http_status', 200)
            ->assertJsonPath('results.0.result.check_out_at', null)
            ->assertJsonPath('results.1.status', 'applied')
            ->assertJsonPath('results.1.result.worked_minutes', 535);

        $day = AttendanceDay::forDate($this->employee->getKey(), '2026-10-28');
        $this->assertSame('09:07', $day->check_in_at->format('H:i'));
        $this->assertSame('18:02', $day->check_out_at->format('H:i'));
        $this->assertSame(AttendanceDay::STATUS_PRESENT, $day->status);
        $this->assertSame(AttendanceDay::SOURCE_SELF_SERVICE, $day->source);
        $this->assertSame(31.5204, (float) $day->latitude);
        $this->assertSame(1, AttendanceDay::count());

        // Both commands are on record, with the device's time kept and ours stamped.
        $this->assertSame(2, SyncCommand::count());
        $row = SyncCommand::where('client_id', $in['id'])->firstOrFail();
        $this->assertSame('attendance', $row->workflow);
        $this->assertSame($this->user->getKey(), (int) $row->user_id);
        $this->assertSame('2026-10-28 09:07:00', $row->captured_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 20:00:00', $row->received_at->format('Y-m-d H:i:s'));
        $this->assertSame($response->json('results.0.result'), $row->result);
    }

    public function test_a_replayed_command_answers_from_its_row_and_applies_nothing(): void
    {
        $in = $this->punch('2026-10-28T09:07:00Z');

        $first = $this->sync([$in])->assertOk()->json('results.0');

        Carbon::setTestNow('2026-10-28 20:05:00');

        $this->sync([$in])
            ->assertOk()
            ->assertJsonPath('summary.replayed', 1)
            ->assertJsonPath('summary.applied', 0)
            ->assertJsonPath('results.0.status', 'replayed')
            ->assertJsonPath('results.0.http_status', 200)
            ->assertJsonPath('results.0.result', $first['result'])
            // The original receipt, not a new one.
            ->assertJsonPath('results.0.received_at', $first['received_at']);

        $this->assertSame(1, SyncCommand::count());
        $this->assertNull(AttendanceDay::forDate($this->employee->getKey(), '2026-10-28')->check_out_at, 'A replay was applied as a second punch.');
    }

    public function test_punches_received_out_of_order_still_make_the_earlier_moment_the_check_in(): void
    {
        $evening = $this->punch('2026-10-28T18:02:00Z');
        $morning = $this->punch('2026-10-28T09:07:00Z');

        $this->sync([$evening])->assertOk()->assertJsonPath('results.0.result.check_out_at', null);
        $this->sync([$morning])->assertOk()->assertJsonPath('results.0.status', 'applied');

        $day = AttendanceDay::forDate($this->employee->getKey(), '2026-10-28');
        $this->assertSame('09:07', $day->check_in_at->format('H:i'));
        $this->assertSame('18:02', $day->check_out_at->format('H:i'));
        $this->assertSame(535, $day->worked_minutes);

        // Received order is the record; the device's clock is kept beside it, not used to reorder.
        $rows = SyncCommand::orderBy('id')->get();
        $this->assertSame([$evening['id'], $morning['id']], $rows->pluck('client_id')->all());
        $this->assertSame(['18:02', '09:07'], $rows->map(fn (SyncCommand $c) => $c->captured_at->format('H:i'))->all());
    }

    public function test_a_batch_cut_short_resumes_and_a_refusal_is_kept(): void
    {
        $a = $this->punch('2026-10-28T09:07:00Z');
        $b = $this->punch('2026-10-28T18:02:00Z');
        $c = $this->punch('2026-10-28T19:30:00Z');

        // The connection died after the first command was applied; the device never heard.
        $this->sync([$a])->assertOk()->assertJsonPath('results.0.status', 'applied');

        // It resends the whole batch: the first answers from its row, the second applies, the
        // third is a third punch and is refused with the reason the online path gives.
        $this->sync([$a, $b, $c])
            ->assertOk()
            ->assertJsonPath('summary', ['applied' => 1, 'replayed' => 1, 'rejected' => 1, 'stale' => 0, 'conflict' => 0, 'failed' => 0, 'skipped' => 0])
            ->assertJsonPath('results.0.status', 'replayed')
            ->assertJsonPath('results.1.status', 'applied')
            ->assertJsonPath('results.2.status', 'rejected')
            ->assertJsonPath('results.2.http_status', 422)
            ->assertJsonPath('results.2.result.message', fn (string $m) => str_contains($m, 'regularization'));

        // The refusal is on record and answers the same way without asking the recorder again.
        $this->assertSame(SyncCommand::STATUS_REJECTED, SyncCommand::where('client_id', $c['id'])->value('status'));

        $this->sync([$c])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'replayed')
            ->assertJsonPath('results.0.http_status', 422);

        $this->assertSame(3, SyncCommand::count());
        $this->assertSame('18:02', AttendanceDay::forDate($this->employee->getKey(), '2026-10-28')->check_out_at->format('H:i'));
    }

    public function test_the_same_tap_twice_is_one_punch(): void
    {
        $this->sync([$this->punch('2026-10-28T09:07:00Z'), $this->punch('2026-10-28T09:07:40Z')])
            ->assertOk()
            ->assertJsonPath('summary.applied', 2);

        $day = AttendanceDay::forDate($this->employee->getKey(), '2026-10-28');
        $this->assertSame('09:07', $day->check_in_at->format('H:i'));
        $this->assertNull($day->check_out_at, 'A double tap became a forty-second working day.');
    }

    public function test_somebody_elses_command_id_is_a_conflict_not_a_replay(): void
    {
        $in = $this->punch('2026-10-28T09:07:00Z');
        $this->sync([$in])->assertOk();

        $other = User::factory()->create(['status' => 1]);
        $other->companies()->syncWithoutDetaching([$this->tenant->getKey()]);
        Employee::create([
            'user_id' => $other->getKey(), 'employee_id' => 'EMP-2', 'name' => 'Bilal', 'gender' => 'Male',
            'is_active' => true, 'date_of_joining' => '2020-01-01',
        ]);
        $this->actingAs($other);

        $this->sync([$in])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.http_status', 409)
            // The six keys every entry carries, and nothing of the other account's receipt.
            ->assertJsonPath('results.0.received_at', null);

        $this->assertSame(1, SyncCommand::count());
        $this->assertSame(1, AttendanceDay::count());
    }

    public function test_a_caller_without_an_employee_profile_is_refused_not_failed(): void
    {
        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant($this->tenant);

        $this->sync([$this->punch('2026-10-28T09:07:00Z')])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.http_status', 422)
            ->assertJsonPath('results.0.result.message', 'Employee profile not found.');

        $this->assertSame(0, AttendanceDay::count());
    }

    public function test_a_malformed_batch_is_refused_whole(): void
    {
        $this->sync([['id' => 'not-a-ulid', 'payload' => ['type' => 'punch', 'punched_at' => '2026-10-28T09:07:00Z']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commands.0.id']);

        $this->sync([['id' => (string) Str::ulid(), 'payload' => ['type' => 'sale', 'punched_at' => '2026-10-28T09:07:00Z']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commands.0.payload.type']);

        $this->postJson('/api/v1/sync/attendance', ['commands' => []])->assertStatus(422);

        $this->assertSame(0, SyncCommand::count());
    }

    public function test_a_company_without_attendance_has_no_inbox(): void
    {
        $this->license(false);

        $this->sync([$this->punch('2026-10-28T09:07:00Z')])->assertForbidden();
        $this->getJson('/api/v1/sync/attendance/changes')->assertForbidden();

        // And a workflow nobody registered is not an endpoint at all.
        $this->postJson('/api/v1/sync/till', ['commands' => []])->assertNotFound();
    }

    public function test_the_inbox_is_on_the_versioned_surface_only(): void
    {
        $this->postJson('/api/sync/attendance', ['commands' => [$this->punch('2026-10-28T09:07:00Z')]])->assertNotFound();
    }

    public function test_the_panel_replays_through_the_session_route(): void
    {
        $in = $this->punch('2026-10-28T09:07:00Z');

        $this->postJson('/'.$this->tenant->slug.'/sync/attendance', ['commands' => [$in]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        // Same inbox: the API sees the command the panel sent.
        $this->sync([$in])->assertOk()->assertJsonPath('results.0.status', 'replayed');
        $this->assertSame(1, AttendanceDay::count());
    }

    public function test_the_changes_read_pages_by_cursor_within_what_the_caller_may_see(): void
    {
        $colleague = Employee::create([
            'employee_id' => 'EMP-2', 'name' => 'Bilal', 'gender' => 'Male', 'is_active' => true, 'date_of_joining' => '2020-01-01',
        ]);

        Carbon::setTestNow('2026-10-26 18:00:00');
        $monday = AttendanceDay::create(['employee_id' => $this->employee->getKey(), 'date' => '2026-10-26', 'status' => AttendanceDay::STATUS_PRESENT]);
        Carbon::setTestNow('2026-10-27 18:00:00');
        $tuesday = AttendanceDay::create(['employee_id' => $this->employee->getKey(), 'date' => '2026-10-27', 'status' => AttendanceDay::STATUS_PRESENT]);
        Carbon::setTestNow('2026-10-28 18:00:00');
        AttendanceDay::create(['employee_id' => $colleague->getKey(), 'date' => '2026-10-28', 'status' => AttendanceDay::STATUS_PRESENT]);

        // Page one: the caller's own days, oldest first, one at a time.
        $first = $this->getJson('/api/v1/sync/attendance/changes?limit=1')
            ->assertOk()
            ->assertJsonPath('workflow', 'attendance')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $monday->getKey())
            ->assertJsonPath('data.0.date', '2026-10-26')
            ->assertJsonPath('deleted', [])
            ->assertJsonPath('has_more', true);

        $second = $this->getJson('/api/v1/sync/attendance/changes?limit=1&since='.urlencode($first->json('cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tuesday->getKey())
            ->assertJsonPath('has_more', false);

        // Nothing since the end of page two — the colleague's day is not the caller's to see.
        $this->getJson('/api/v1/sync/attendance/changes?since='.urlencode($second->json('cursor')))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('cursor', $second->json('cursor'));

        // A correction moves the day past the cursor and it comes round again.
        Carbon::setTestNow('2026-10-29 09:00:00');
        $monday->update(['check_in_at' => '2026-10-26 09:00:00']);

        $this->getJson('/api/v1/sync/attendance/changes?since='.urlencode($second->json('cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $monday->getKey())
            ->assertJsonPath('data.0.check_in_at', fn (string $at) => str_starts_with($at, '2026-10-26T09:00:00'));

        // A cursor somebody edited reads from the start rather than throwing.
        $this->getJson('/api/v1/sync/attendance/changes?since=nonsense')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_inbox_is_tenant_side_so_one_phone_in_two_companies_cannot_collide(): void
    {
        // The table lives in each company's database: the same ULID sent to two companies is two rows
        // in two databases, and no query can see across. Asserted as structure, because the suite runs
        // every company on one connection (tests/Concerns/UsesRealTenantDatabase.php says why).
        $this->assertInstanceOf(TenantModel::class, new SyncCommand);
        $this->assertFileExists(base_path('database/migrations/tenant/2026_11_08_090000_create_sync_commands_table.php'));
    }

    public function test_a_failure_of_ours_stops_the_batch_and_answers_in_the_one_shape(): void
    {
        // A workflow that breaks on its second command, the way a bug of ours would: not a refusal
        // the device should stop on, but something to retry once it is fixed.
        $workflow = new class implements SyncWorkflow
        {
            public int $calls = 0;

            public function key(): string
            {
                return 'breaks';
            }

            public function module(): string
            {
                return 'attendance';
            }

            public function rules(): array
            {
                return [];
            }

            public function apply(SyncCommand $command, User $user): array
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('a bug of ours');
                }

                return ['ok' => true];
            }

            public function changesSince(?string $cursor, User $user, int $limit): SyncChanges
            {
                throw new LogicException('not read here');
            }
        };

        $commands = [$this->punch('2026-10-28T09:07:00Z'), $this->punch('2026-10-28T18:02:00Z'), $this->punch('2026-10-28T19:30:00Z')];

        $batch = app(SyncInbox::class)->receive($workflow, $this->user, $commands);

        $this->assertSame(['applied' => 1, 'replayed' => 0, 'rejected' => 0, 'stale' => 0, 'conflict' => 0, 'failed' => 1, 'skipped' => 1], $batch['summary']);

        // Every entry has the same six keys, whether a row answers for it or nothing does.
        foreach ($batch['results'] as $entry) {
            $this->assertSame(['id', 'status', 'http_status', 'captured_at', 'received_at', 'result'], array_keys($entry));
        }

        [$applied, $failed, $skipped] = $batch['results'];
        $this->assertSame(['applied', 200], [$applied['status'], $applied['http_status']]);
        $this->assertSame(['failed', 500], [$failed['status'], $failed['http_status']]);
        $this->assertTrue(Carbon::parse($failed['captured_at'])->equalTo('2026-10-28 18:02:00'), 'The failed command keeps the moment the device captured it.');
        $this->assertTrue(Carbon::parse($failed['received_at'])->equalTo(Carbon::now()), 'The failed command carries the moment we took it.');
        $this->assertSame(['skipped', null, null, null], [$skipped['status'], $skipped['http_status'], $skipped['received_at'], $skipped['result']]);
        $this->assertTrue(Carbon::parse($skipped['captured_at'])->equalTo('2026-10-28 19:30:00'));

        // Only the applied command is on record: the failure is retried, the skipped one was never reached.
        $this->assertSame([$commands[0]['id']], SyncCommand::pluck('client_id')->all());
    }
}
