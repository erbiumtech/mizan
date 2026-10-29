<?php

namespace App\Modules\Leave\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveDay;
use App\Modules\Leave\Models\LeaveRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

/** A month grid over LeaveDay — docs/saas-launch-plan.md §14.4. Who is out when, at a glance. */
class LeaveCalendar extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.leave-calendar';

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $title = 'Leave Calendar';

    protected static ?int $navigationSort = 35;

    public string $month;

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('LeaveRequestView') ?? false);
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function previous(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->subMonth()->format('Y-m');
    }

    public function next(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->addMonth()->format('Y-m');
    }

    /** @return array{days: array<int, Carbon>, rows: array<int, array{employee: string, cells: array<string, array{colour: ?string, label: string, portion: float}|null>}>} */
    public function grid(): array
    {
        $start = Carbon::parse($this->month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $days = [];

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $days[] = $d->copy();
        }

        $leaveDays = LeaveDay::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('request', fn ($q) => $q->where('status', LeaveRequest::STATUS_APPROVED))
            ->with('request.leaveType')
            ->get();

        $byEmployee = $leaveDays->groupBy(fn (LeaveDay $day) => $day->request->employee_id);
        $employees = Employee::query()->with('user')->whereKey($byEmployee->keys()->all())->orderBy('employee_id')->get();
        $rows = [];

        foreach ($employees as $employee) {
            $cells = [];

            foreach ($byEmployee[$employee->getKey()] as $day) {
                $type = $day->request->leaveType;
                $cells[$day->date->toDateString()] = [
                    'colour' => $type?->colour ?: '#9ca3af',
                    'label' => ($type?->code ? strtoupper(substr($type->code, 0, 2)) : 'L').((float) $day->portion < 1 ? '½' : ''),
                    'portion' => (float) $day->portion,
                ];
            }

            $rows[] = ['employee' => $employee->display_label, 'cells' => $cells];
        }

        return ['days' => $days, 'rows' => $rows];
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leave-calendar', 'Leave Calendar: Help'),
            Action::make('previous')->label(__('Previous'))->icon('heroicon-o-chevron-left')->color('gray')->action(fn () => $this->previous()),
            Action::make('next')->label(__('Next'))->icon('heroicon-o-chevron-right')->color('gray')->iconPosition('after')->action(fn () => $this->next()),
        ];
    }
}
