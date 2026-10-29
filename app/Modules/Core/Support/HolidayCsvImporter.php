<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Core\Services\HolidayCalendar;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** A year of holidays from a spreadsheet — docs/saas-launch-plan.md §14.4. */
class HolidayCsvImporter implements CsvImporter
{
    public function key(): string
    {
        return 'holidays';
    }

    public function label(): string
    {
        return 'Holidays';
    }

    public function columns(): array
    {
        return ['date', 'name', 'kind', 'recurring', 'location'];
    }

    public function example(): array
    {
        return ['2027-03-23', 'Pakistan Day', 'public', 'yes', ''];
    }

    public function dateField(): ?array
    {
        return null;
    }

    public function problemWith(array $row): ?string
    {
        if (strtotime((string) ($row['date'] ?? '')) === false) {
            return ($row['date'] ?? '').' is not a date';
        }

        if (trim((string) ($row['name'] ?? '')) === '') {
            return 'no name';
        }

        if (($row['kind'] ?? '') !== '' && ! isset(Holiday::KINDS[strtolower(trim((string) $row['kind']))])) {
            return $row['kind'].' is not a kind (public, optional, restricted)';
        }

        if (($row['location'] ?? '') !== '' && ! WorkLocation::query()->where('name', trim((string) $row['location']))->exists()) {
            return 'no location named '.$row['location'];
        }

        if (Holiday::query()->whereDate('date', Carbon::parse((string) $row['date'])->toDateString())->exists()) {
            return Carbon::parse((string) $row['date'])->toDateString().' is already a holiday';
        }

        return null;
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $written = 0;

        foreach ($rows as $row) {
            Holiday::create([
                'date' => Carbon::parse((string) $row['date'])->toDateString(),
                'name' => trim((string) $row['name']),
                'kind' => ($row['kind'] ?? '') !== '' ? strtolower(trim((string) $row['kind'])) : Holiday::KIND_PUBLIC,
                'is_recurring' => in_array(strtolower(trim((string) ($row['recurring'] ?? ''))), ['yes', 'y', '1', 'true'], true),
                'location_id' => ($row['location'] ?? '') !== '' ? WorkLocation::query()->where('name', trim((string) $row['location']))->value('id') : null,
            ]);
            $written++;
        }

        app(HolidayCalendar::class)->flush();

        return $written;
    }
}
