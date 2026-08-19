<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\LabourRecordResource;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Services\TimesheetLabourImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListLabourRecords extends ListRecords
{
    protected static string $resource = LabourRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-site-sheets', 'Site sheets: Help'),
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    /**
     * §7.1's timesheet import, and it is **absent rather than broken** without the module (§18.1).
     *
     * The entries are copied into drafts rather than costed where they lie, because `timesheet_entries` bills and never
     * costs — its ladder resolves charge-out rates, and pricing a job from those overstates every margin by the
     * mark-up. What arrives is a draft that §7's own rate ladder prices at approval.
     */
    private function importAction(): Action
    {
        return Action::make('importTimesheets')
            ->label('Import from timesheets')
            ->icon('heroicon-o-arrow-down-on-square')
            ->color('gray')
            ->visible(fn (): bool => app(TimesheetLabourImport::class)->isAvailable()
                && (auth()->user()?->can('create', LabourRecord::class) ?? false))
            ->modalHeading('Bring approved timesheet entries in as site sheets')
            ->modalDescription('Only approved entries, only on projects a job names, and only for people the worker register links to an employee. Everything arrives as a draft — the rate is worked out and frozen when you approve it, from the construction rate ladder rather than the charge-out one.')
            ->schema([
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->required()
                    ->default(now()->startOfMonth()),

                DatePicker::make('to')
                    ->label('To')
                    ->native(false)
                    ->required()
                    ->default(now()),

                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->whereNotNull('project_id')->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Every job that names a project')
                    // Only jobs that name a project can match anything, so the picker shows only those rather than
                    // offering a choice that silently returns nothing.
                    ->helperText('Only jobs linked to a project appear here — the link is what matches a timesheet entry to a job.'),
            ])
            ->action(function (array $data): void {
                $import = app(TimesheetLabourImport::class);
                $job = ($data['job_id'] ?? null) ? Job::query()->find($data['job_id']) : null;

                try {
                    $summary = $import->import($data['from'], $data['to'], $job);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                // Skipped rows are shown rather than counted, up to a readable number: "three could not be imported"
                // with no reason is a message that sends somebody to a developer.
                Notification::make()
                    ->title($summary->summary())
                    ->body($summary->skipped === [] ? null : implode("\n\n", array_slice($summary->skipped, 0, 5))
                        .(count($summary->skipped) > 5 ? "\n\n…and ".(count($summary->skipped) - 5).' more.' : ''))
                    ->color($summary->skipped === [] ? 'success' : 'warning')
                    ->persistent()
                    ->send();
            });
    }
}
