<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Holidays\Pages\ListHolidays;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PakistanPublicHolidays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The federal holiday list, and the action that adds it to a company's calendar.
 *
 * The list is data, so the tests are about its shape — one row per date, every date in its year — and
 * about the two things the action promises: a second run adds nothing, and a row somebody entered is
 * never overwritten by the list.
 */
class PakistanPublicHolidaysTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_every_listed_year_has_one_row_per_date_inside_that_year(): void
    {
        $this->assertNotEmpty(PakistanPublicHolidays::years());

        foreach (PakistanPublicHolidays::years() as $year) {
            $rows = PakistanPublicHolidays::for($year);
            $dates = array_column($rows, 'date');

            $this->assertSame($dates, array_unique($dates), "{$year}: a date is listed twice");

            foreach ($rows as $row) {
                $this->assertSame($year, Carbon::parse($row['date'])->year, "{$row['name']} is not in {$year}");
                $this->assertNotSame('', $row['name']);
                $this->assertNotSame('', $row['notes']);
            }
        }
    }

    public function test_the_gazetted_2026_list_carries_the_notified_dates(): void
    {
        $byDate = collect(PakistanPublicHolidays::for(2026))->keyBy('date');

        $this->assertStringContainsString('Independence Day', $byDate['2026-08-14']['name']);
        $this->assertTrue($byDate['2026-08-14']['is_recurring'], 'a national day recurs');

        // Eid ul-Fitr 21–23 March, with the third day sharing its row with Pakistan Day.
        $this->assertStringContainsString('Eid ul-Fitr', $byDate['2026-03-21']['name']);
        $this->assertStringContainsString('Pakistan Day', $byDate['2026-03-23']['name']);
        $this->assertStringContainsString('Eid ul-Fitr', $byDate['2026-03-23']['name']);

        // Moved from the 25th by the later notification; the note says so.
        $this->assertStringContainsString('Eid Milad-un-Nabi', $byDate['2026-08-26']['name']);
        $this->assertFalse($byDate['2026-08-26']['is_recurring'], 'a lunar date never recurs on the same day');
        $this->assertArrayNotHasKey('2026-08-25', $byDate);
    }

    public function test_adding_a_year_is_idempotent_and_keeps_what_was_already_entered(): void
    {
        Holiday::create(['date' => '2026-08-14', 'name' => 'Yaum-e-Azadi', 'notes' => 'Factory closed two days']);

        $first = PakistanPublicHolidays::addMissing(2026);

        $this->assertSame(count(PakistanPublicHolidays::for(2026)) - 1, $first['added']);
        $this->assertSame(1, $first['skipped']);
        $this->assertSame('Yaum-e-Azadi', Holiday::whereDate('date', '2026-08-14')->value('name'), 'the row somebody entered is kept');

        $second = PakistanPublicHolidays::addMissing(2026);

        $this->assertSame(0, $second['added']);
        $this->assertSame(count(PakistanPublicHolidays::for(2026)), $second['skipped']);
        $this->assertSame(count(PakistanPublicHolidays::for(2026)), Holiday::count());
    }

    public function test_the_header_action_adds_the_chosen_year(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        Livewire::test(ListHolidays::class)
            ->callAction('addPakistanHolidays', ['year' => 2027])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(count(PakistanPublicHolidays::for(2027)), Holiday::count());
        $this->assertSame(0, Holiday::whereYear('date', 2026)->count(), 'only the chosen year');
    }
}
