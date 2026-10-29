<?php

namespace App\Modules\Employees\Rules;

use Illuminate\Support\Carbon;

/** The five windows a metric is read over — docs/saas-launch-plan.md §8.9. */
final class Window
{
    public const THIS_MONTH = 'this_month';

    public const LAST_MONTH = 'last_month';

    public const LAST_3_MONTHS = 'last_3_months';

    public const YEAR_TO_DATE = 'year_to_date';

    public const ALL_TIME = 'all_time';

    public const LABELS = [
        self::THIS_MONTH => 'This month',
        self::LAST_MONTH => 'Last month',
        self::LAST_3_MONTHS => 'Last 3 months',
        self::YEAR_TO_DATE => 'Year to date',
        self::ALL_TIME => 'All time',
    ];

    public function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function make(string $key, Carbon $asOf): self
    {
        $asOf = $asOf->copy()->startOfDay();

        return match ($key) {
            self::LAST_MONTH => new self($key, $asOf->copy()->subMonthNoOverflow()->startOfMonth(), $asOf->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            self::LAST_3_MONTHS => new self($key, $asOf->copy()->subMonthsNoOverflow(3)->startOfMonth(), $asOf->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            self::YEAR_TO_DATE => new self($key, $asOf->copy()->startOfYear(), $asOf),
            self::ALL_TIME => new self($key, Carbon::parse('1970-01-01'), $asOf),
            default => new self(self::THIS_MONTH, $asOf->copy()->startOfMonth(), $asOf),
        };
    }

    public static function normalise(?string $key): string
    {
        return isset(self::LABELS[(string) $key]) ? (string) $key : self::THIS_MONTH;
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }
}
