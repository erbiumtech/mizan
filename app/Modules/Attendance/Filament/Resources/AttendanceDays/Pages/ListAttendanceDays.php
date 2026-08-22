<?php

namespace App\Modules\Attendance\Filament\Resources\AttendanceDays\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Attendance\Filament\Resources\AttendanceDays\AttendanceDayResource;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceCalendar;
use App\Modules\Attendance\Services\AttendanceImport;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('attendance', 'Attendance: Help'),

            /**
             * Open a month for filling in.
             *
             * Weekly offs and holidays are written with their own status so the month
             * reads correctly from the start, and working days are left `not_marked`
             * — the honest default, and what the badge counts. Never overwrites a day
             * that already exists.
             */
            Action::make('openMonth')
                ->label('Open a month')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->schema([
                    Select::make('employee_id')
                        ->label('Employee')
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
                ->label('Import a CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('create', AttendanceDay::class) ?? false)
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->disk('local')
                        ->directory('attendance-imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('Columns: employee_id, date, status, check_in, check_out, note. Status accepts P/A/L/H/W/HD/WFH or the full word.'),

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

                    $result = $data['mode'] === 'import'
                        ? $import->import($csv)
                        : $import->preview($csv);

                    $notification = Notification::make()->title($result->summary());

                    // Errors are named, never counted: "12 rows failed" sends somebody
                    // hunting through the file.
                    $result->hasErrors()
                        ? $notification->warning()->body(implode("\n", array_slice($result->errors, 0, 5)))
                        : $notification->success();

                    $notification->persistent()->send();
                }),

            CreateAction::make()->label('Record a day'),
        ];
    }
}
