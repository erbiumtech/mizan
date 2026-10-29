<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * How a company writes a date on a document — docs/saas-launch-plan.md §14.10.
 *
 * **Four options, not a format box.** A free format string lets somebody type
 * `Y-m-d H:i:s` onto a payslip, or `l jS F` onto an invoice that has to fit a column,
 * and the four below are every way a Pakistani company actually writes a date. The
 * dropdown is also the documentation: nobody has to know what `d/m/Y` means.
 *
 * **Documents only.** The panel's own screens keep their own formats, which are
 * chosen per column for width and sorting and are not a company's to change.
 */
final class CompanyDates
{
    public const DEFAULT = 'd M Y';

    /** @var array<string, string> format => how it reads */
    public const FORMATS = [
        'd M Y' => '09 Mar 2026',
        'd/m/Y' => '09/03/2026',
        'd-m-Y' => '09-03-2026',
        'd F Y' => '09 March 2026',
    ];

    /** The chosen format, or the shipped one where the choice is missing or unknown. */
    public static function format(): string
    {
        $format = (string) setting('company.date_format', self::DEFAULT);

        return array_key_exists($format, self::FORMATS) ? $format : self::DEFAULT;
    }

    /**
     * One date, as this company writes it.
     *
     * Null in, empty out. A document printing "01 Jan 1970" because a column was null
     * is worse than one printing nothing.
     */
    public static function write(Carbon|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        return ($date instanceof Carbon ? $date : Carbon::parse($date))->format(self::format());
    }
}
