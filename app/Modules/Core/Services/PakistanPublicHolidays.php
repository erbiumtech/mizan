<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * The federal public holidays of Pakistan, by year, ready to drop into a company's calendar.
 *
 * Two kinds of date, and the notes on each row say which. The national days are fixed and
 * marked recurring. The Islamic festivals follow the lunar calendar: for a year the Cabinet
 * Division has gazetted they are the notified dates, and for a year it has not they are
 * astronomical estimates that the Ruet-e-Hilal Committee's sighting can move by a day —
 * so those rows carry a "tentative" note, and the help text says to confirm them. Nothing
 * here is ever worked out automatically for a year this class does not list.
 *
 * Where a festival day coincides with a national day (Eid ul-Fitr's third day on Pakistan
 * Day in 2026, Eid ul-Azha's second on Youm-e-Takbeer) one row carries both names, because
 * the holidays table allows one row per date and counting a day twice is the failure it
 * exists to prevent. Bank holidays and the denominational optional holidays are left out:
 * neither closes a company.
 *
 * Sources: Cabinet Division notification for 2026 (19 January 2026), with Eid Milad-un-Nabi
 * moved to 26 August by the later notification of the Prime Minister's Office; 2027 lunar
 * dates from published astronomical calendars.
 */
class PakistanPublicHolidays
{
    private const GAZETTED = 'Gazetted by the Cabinet Division.';

    private const TENTATIVE = 'Tentative — follows the lunar calendar and may move by a day on moon sighting. Confirm against the Cabinet Division notification for the year.';

    /** @return array<int, int> the years this class can fill in */
    public static function years(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<int, array{date: string, name: string, is_recurring: bool, notes: string}>
     */
    public static function for(int $year): array
    {
        return self::all()[$year] ?? [];
    }

    /**
     * Add the year's holidays the calendar does not already have.
     *
     * A date already listed is left exactly as it is — its name, its notes — because somebody
     * entered it, and what they entered outranks a list. Counted as skipped rather than
     * overwritten, so the notification says what happened.
     *
     * @return array{added: int, skipped: int}
     */
    public static function addMissing(int $year): array
    {
        $rows = self::for($year);

        // By year and then normalised, not whereIn on the strings: a `date` cast is stored with a
        // time on SQLite, where '2026-08-14' and '2026-08-14 00:00:00' are different strings.
        $present = Holiday::query()
            ->whereYear('date', $year)
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->all();

        $added = 0;

        foreach ($rows as $row) {
            if (in_array($row['date'], $present, true)) {
                continue;
            }

            Holiday::create($row);
            $added++;
        }

        return ['added' => $added, 'skipped' => count($rows) - $added];
    }

    /**
     * @return array<int, array<int, array{date: string, name: string, is_recurring: bool, notes: string}>>
     */
    private static function all(): array
    {
        return [
            2026 => [
                self::fixed('2026-02-05', 'Kashmir Day'),
                self::lunar('2026-03-21', 'Eid ul-Fitr (day 1)', self::GAZETTED),
                self::lunar('2026-03-22', 'Eid ul-Fitr (day 2)', self::GAZETTED),
                self::fixed('2026-03-23', 'Pakistan Day / Eid ul-Fitr (day 3)'),
                self::fixed('2026-05-01', 'Labour Day'),
                self::lunar('2026-05-27', 'Eid ul-Azha (day 1)', self::GAZETTED),
                self::fixed('2026-05-28', 'Youm-e-Takbeer / Eid ul-Azha (day 2)'),
                self::lunar('2026-05-29', 'Eid ul-Azha (day 3)', self::GAZETTED),
                self::lunar('2026-06-24', 'Ashura (9th Muharram)', self::GAZETTED),
                self::lunar('2026-06-25', 'Ashura (10th Muharram)', self::GAZETTED),
                self::fixed('2026-08-14', 'Independence Day'),
                self::lunar('2026-08-26', 'Eid Milad-un-Nabi (12th Rabi ul-Awwal)', 'Notified for 26 August after moon sighting; the January calendar had said the 25th.'),
                self::fixed('2026-11-09', 'Iqbal Day'),
                self::fixed('2026-12-25', 'Quaid-e-Azam Day / Christmas'),
            ],
            2027 => [
                self::fixed('2027-02-05', 'Kashmir Day'),
                self::lunar('2027-03-10', 'Eid ul-Fitr (day 1)', self::TENTATIVE),
                self::lunar('2027-03-11', 'Eid ul-Fitr (day 2)', self::TENTATIVE),
                self::lunar('2027-03-12', 'Eid ul-Fitr (day 3)', self::TENTATIVE),
                self::fixed('2027-03-23', 'Pakistan Day'),
                self::fixed('2027-05-01', 'Labour Day'),
                self::lunar('2027-05-17', 'Eid ul-Azha (day 1)', self::TENTATIVE),
                self::lunar('2027-05-18', 'Eid ul-Azha (day 2)', self::TENTATIVE),
                self::lunar('2027-05-19', 'Eid ul-Azha (day 3)', self::TENTATIVE),
                self::fixed('2027-05-28', 'Youm-e-Takbeer'),
                self::lunar('2027-06-14', 'Ashura (9th Muharram)', self::TENTATIVE),
                self::lunar('2027-06-15', 'Ashura (10th Muharram)', self::TENTATIVE),
                self::fixed('2027-08-14', 'Independence Day'),
                self::lunar('2027-08-15', 'Eid Milad-un-Nabi (12th Rabi ul-Awwal)', self::TENTATIVE),
                self::fixed('2027-11-09', 'Iqbal Day'),
                self::fixed('2027-12-25', 'Quaid-e-Azam Day / Christmas'),
            ],
        ];
    }

    /** @return array{date: string, name: string, is_recurring: bool, notes: string} */
    private static function fixed(string $date, string $name): array
    {
        return ['date' => $date, 'name' => $name, 'is_recurring' => true, 'notes' => 'National day, same date every year.'];
    }

    /** @return array{date: string, name: string, is_recurring: bool, notes: string} */
    private static function lunar(string $date, string $name, string $notes): array
    {
        return ['date' => $date, 'name' => $name, 'is_recurring' => false, 'notes' => $notes];
    }
}
