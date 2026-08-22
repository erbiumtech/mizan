<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Holiday;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What the holidays table knows, and only that.
 *
 * Leave and attendance are both built on top of this, so the API is the part
 * that matters: they will ask the same three questions, and asking them
 * differently in each module is how the two end up disagreeing about a day.
 *
 * There is deliberately no isWorkingDay(). A working day is a holiday question
 * *and* a weekend question, and weekends come from work patterns in attendance,
 * which do not exist yet. A method here that answered by assuming Sat/Sun would
 * be wrong for every company on a six-day week and wrong silently — callers
 * would stop asking the module that actually knows.
 *
 * Registered as a singleton (CoreServiceProvider) and it reads the whole table
 * once, because the caller this exists for is the leave-day generator: it walks
 * a request day by day, so a query per day is a query per day of leave. A
 * company's holidays are a dozen rows a year, which is cheaper to hold than to
 * query twice.
 */
class HolidayCalendar
{
    /**
     * All holidays, keyed by tenant.
     *
     * Keyed rather than a single list because the singleton outlives a tenant in
     * a queued command that fans out over companies — the same instance answers
     * for each one in turn, and an unkeyed cache would hand the second company
     * the first company's calendar.
     *
     * @var array<int|string, Collection<int, Holiday>>
     */
    protected array $cache = [];

    /**
     * The same rows as a date => true set, per tenant.
     *
     * Held separately rather than derived on each call because isHoliday() is
     * asked once per calendar day of a leave request, and rebuilding the list to
     * scan it would make the loop quadratic in the calendar. isset() on a set is
     * the shape that question wants.
     *
     * @var array<int|string, array<string, true>>
     */
    protected array $dates = [];

    public function isHoliday(string|CarbonInterface $date): bool
    {
        return isset($this->dateSet()[$this->normalise($date)]);
    }

    /**
     * The holidays falling on or between two dates, earliest first.
     *
     * Both ends are inclusive: a request that runs to the 14th and a holiday on
     * the 14th is the case this is asked about most often.
     *
     * @return Collection<int, Holiday>
     */
    public function between(string|CarbonInterface $from, string|CarbonInterface $to): Collection
    {
        [$from, $to] = [$this->normalise($from), $this->normalise($to)];

        return $this->all()
            ->filter(fn (Holiday $holiday): bool => $holiday->date->toDateString() >= $from
                && $holiday->date->toDateString() <= $to)
            ->values();
    }

    /**
     * The same range as Y-m-d strings.
     *
     * The generator walks a leave request one day at a time and asks whether each
     * day is in here, so it wants strings it can compare directly rather than
     * models it has to unwrap on every iteration.
     *
     * @return array<int, string>
     */
    public function datesBetween(string|CarbonInterface $from, string|CarbonInterface $to): array
    {
        return $this->between($from, $to)
            ->map(fn (Holiday $holiday): string => $holiday->date->toDateString())
            ->all();
    }

    /**
     * Called by Holiday's saved/deleted hooks. Everything, not one tenant: a
     * write reaches here from whichever company is current, and dropping the lot
     * costs one query to rebuild.
     */
    public function flush(): void
    {
        $this->cache = [];
        $this->dates = [];
    }

    /** @return Collection<int, Holiday> */
    protected function all(): Collection
    {
        $tenantKey = $this->tenantKey();

        if (! array_key_exists($tenantKey, $this->cache)) {
            $this->cache[$tenantKey] = $this->load();
        }

        return $this->cache[$tenantKey];
    }

    /** @return array<string, true> */
    protected function dateSet(): array
    {
        $tenantKey = $this->tenantKey();

        if (! array_key_exists($tenantKey, $this->dates)) {
            $this->dates[$tenantKey] = $this->all()
                ->mapWithKeys(fn (Holiday $holiday): array => [$holiday->date->toDateString() => true])
                ->all();
        }

        return $this->dates[$tenantKey];
    }

    /** @return Collection<int, Holiday> */
    protected function load(): Collection
    {
        try {
            return Holiday::orderBy('date')->get();
        } catch (Throwable) {
            // The table is not there yet — a company mid-provisioning, or a
            // landlord-context call. No holidays is the right answer for both,
            // and is what TenantSettings does for the same situation.
            return new Collection;
        }
    }

    protected function tenantKey(): int|string
    {
        return Company::current()?->getKey() ?? 'default';
    }

    /** Whatever the caller had, as the Y-m-d the rows are compared on. */
    protected function normalise(string|CarbonInterface $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();
    }
}
