<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceDays\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\AttendanceDayResource;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceCalendar;
use App\Modules\Attendance\Services\AttendanceImport;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('attendance', 'Attendance: Help'),

            $this->clockAction(),
            $this->markAllPresentAction(),
            $this->importTemplateAction(),

            /**
             * Open a month for filling in.
             *
             * Weekly offs and holidays are written with their own status so the month
             * reads correctly from the start, and working days are left `not_marked`
             * — the honest default, and what the badge counts. Never overwrites a day
             * that already exists.
             */
            Action::make('openMonth')
                ->label(__('Open a month'))
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->schema([
                    Select::make('employee_id')
                        ->label(__('Employee'))
                        ->options(fn (): array => app(EmployeeAccess::class)
                            ->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                            ->all())
                        ->searchable()
                        ->helperText('Leave blank to open the month for everybody you can see.'),

                    Select::make('month')
                        ->options(collect(range(0, 5))
                            ->mapWithKeys(fn (int $back): array => [
                                now()->subMonths($back)->format('Y-m') => now()->subMonths($back)->format('F Y'),
                            ])
                            ->all())
                        ->default(now()->format('Y-m'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    [$year, $month] = array_map('intval', explode('-', $data['month']));

                    $employees = $data['employee_id']
                        ? Employee::query()->whereKey($data['employee_id'])->get()
                        : app(EmployeeAccess::class)
                            ->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())
                            ->get();

                    $created = 0;

                    foreach ($employees as $employee) {
                        $created += app(AttendanceCalendar::class)->scaffold($employee, $year, $month);
                    }

                    Notification::make()->success()
                        ->title("Opened {$created} day(s) for filling in.")
                        ->body('Working days start as "not marked" — that is what the badge counts, and it is not the same as absent.')
                        ->send();
                }),

            /**
             * CSV import, with a real dry run.
             *
             * The preview runs every check the write would run — including the refusal
             * to contradict approved leave — and writes nothing, so a bad file is
             * caught before half a month is already in.
             */
            Action::make('import')
                ->label(__('Import a CSV'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('create', AttendanceDay::class) ?? false)
                ->schema([
                    FileUpload::make('file')
                        ->label(__('CSV file'))
                        ->disk('local')
                        ->directory('attendance-imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('Columns: employee_id, date, status, check_in, check_out, note. Status accepts P/A/L/H/W/HD/WFH or the full word. A ZKTeco export is matched on its user number against the employee code.'),

                    Select::make('preset')
                        ->label(__('File layout'))
                        ->options(AttendanceImport::presets())
                        ->default(AttendanceImport::PRESET_CSV)
                        ->required(),

                    Select::make('mode')
                        ->options([
                            'preview' => 'Check the file and report — write nothing',
                            'import' => 'Import it',
                        ])
                        ->default('preview')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $csv = Storage::disk('local')->get($data['file']);
                    $import = app(AttendanceImport::class);

                    $preset = (string) ($data['preset'] ?? AttendanceImport::PRESET_CSV);
                    $result = $data['mode'] === 'import'
                        ? $import->import($csv, $preset)
                        : $import->preview($csv, $preset);

                    $notification = Notification::make()->title($result->summary());

                    // Errors are named, never counted: "12 rows failed" sends somebody
                    // hunting through the file.
                    $result->hasErrors()
                        ? $notification->warning()->body(implode("\n", array_slice($result->errors, 0, 5)))
                        : $notification->success();

                    $notification->persistent()->send();
                }),

            CreateAction::make()->label(__('Record a day')),
        ];
    }

    /**
     * The employee's own punch — docs/saas-launch-plan.md §7.1.
     *
     * "Clock in" until today has a check-in, "Clock out" until it has a check-out,
     * absent after that: the regularization request is the way to correct a day.
     * For whoever is signed in, about themselves only — it needs no Attendance
     * permission beyond reaching this page, which every member of staff may.
     * The modal's one field reads the browser's location while it is open, and
     * submitting without one is allowed: the time is the fact, the place is a note.
     */
    private function clockAction(): Action
    {
        $employee = fn (): ?Employee => app(EmployeeAccess::class)->employeeFor(auth()->user());
        $today = fn (): ?AttendanceDay => ($e = $employee()) ? AttendanceDay::forDate($e->getKey(), now()->startOfDay()) : null;

        return Action::make('clock')
            ->label(fn (): string => $today()?->check_in_at ? 'Clock out' : 'Clock in')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->visible(fn (): bool => $employee() !== null && ! ($today()?->check_in_at && $today()?->check_out_at))
            ->modalHeading(fn (): string => ($today()?->check_in_at ? 'Clock out' : 'Clock in').' — '.now()->format('l j F, H:i'))
            ->modalSubmitActionLabel(fn (): string => $today()?->check_in_at ? 'Clock out' : 'Clock in')
            ->modalWidth('md')
            ->schema([
                ViewField::make('position')
                    ->label(__('Location'))
                    ->view('filament.forms.geolocation')
                    ->dehydrated(),
            ])
            ->action(function (array $data) use ($employee): void {
                $position = is_array($data['position'] ?? null) && isset($data['position']['latitude'], $data['position']['longitude'])
                    ? $data['position']
                    : null;

                try {
                    $day = app(AttendanceRecorder::class)->clock($employee(), $position);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title($day->check_out_at ? 'Clocked out at '.$day->check_out_at->format('H:i') : 'Clocked in at '.$day->check_in_at->format('H:i'))
                    ->body($position ? 'Location recorded.' : 'No location was recorded.')
                    ->send();
            });
    }

    /** Everyone with nothing marked on a date, marked present — docs/saas-launch-plan.md §14.4. */
    protected function markAllPresentAction(): Action
    {
        return Action::make('markAllPresent')
            ->label(__('Mark all present'))
            ->icon('heroicon-o-check')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('AttendanceCreate') ?? false)
            ->schema([
                \Filament\Forms\Components\DatePicker::make('date')->native(false)->default(now())->maxDate(now())->required(),
            ])
            ->modalDescription('Every active employee with no attendance row on the date, and for whom it is a working day, is marked present without times. Rows already there are left alone.')
            ->action(function (array $data): void {
                $date = \Illuminate\Support\Carbon::parse($data['date']);
                $recorder = app(AttendanceRecorder::class);
                $patterns = app(\App\Modules\Attendance\Services\WorkPatternResolver::class);
                $marked = 0;

                foreach (\App\Modules\Employees\Models\Employee::query()->where('is_active', true)->get() as $employee) {
                    if (\App\Modules\Attendance\Models\AttendanceDay::forDate($employee->getKey(), $date) !== null || ! $patterns->isWorkingDay($employee, $date)) {
                        continue;
                    }

                    try {
                        $recorder->record($employee, $date, \App\Modules\Attendance\Models\AttendanceDay::STATUS_PRESENT, null, null, \App\Modules\Attendance\Models\AttendanceDay::SOURCE_MANUAL, 'Marked present for everyone');
                        $marked++;
                    } catch (\InvalidArgumentException) {
                        // On approved leave that day: the recorder refuses, rightly.
                    }
                }

                Notification::make()->success()->title("{$marked} employee(s) marked present for {$date->format('d M Y')}.")->send();
            });
    }

    /** The import's shape, as a file to fill in — docs/saas-launch-plan.md §14.4. */
    protected function importTemplateAction(): Action
    {
        return Action::make('importTemplate')
            ->label(__('Import template'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('AttendanceCreate') ?? false)
            ->action(fn () => response()->streamDownload(
                fn () => print (implode(',', \App\Modules\Attendance\Services\AttendanceImport::COLUMNS)."\nEMP-001,".now()->toDateString().",present,09:00,18:00,\n"),
                'attendance-template.csv',
                ['Content-Type' => 'text/csv'],
            ));
    }
}
