<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Holiday;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

/** The year's holidays as twelve month grids — docs/saas-launch-plan.md §14.4. */
class HolidayCalendarPage extends Page
{
    protected string $view = 'filament.pages.holiday-calendar';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $title = 'Holiday Calendar';

    protected static ?string $navigationLabel = 'Holiday Calendar';

    protected static ?int $navigationSort = 52;

    public int $year;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Holiday::class) ?? false;
    }

    public function mount(): void
    {
        $this->year = now()->year;
    }

    /** @return array<int, array{name: string, days: array<int, array{date: Carbon, holiday: ?Holiday}>}> */
    public function months(): array
    {
        $holidays = Holiday::query()->whereYear('date', $this->year)->orderBy('date')->get()->keyBy(fn (Holiday $h): string => $h->date->toDateString());
        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create($this->year, $m, 1);
            $days = [];

            for ($d = $start->copy(); $d->month === $m; $d->addDay()) {
                $days[] = ['date' => $d->copy(), 'holiday' => $holidays[$d->toDateString()] ?? null];
            }

            $months[] = ['name' => $start->format('F'), 'days' => $days];
        }

        return $months;
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('holiday-calendar', 'Holiday Calendar: Help'),
            Action::make('previousYear')->label((string) ($this->year - 1))->color('gray')->action(fn () => $this->year--),
            Action::make('nextYear')->label((string) ($this->year + 1))->color('gray')->action(fn () => $this->year++),
        ];
    }
}
