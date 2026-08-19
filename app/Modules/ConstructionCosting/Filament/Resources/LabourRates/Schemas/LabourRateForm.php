<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Scope, rate, dates.
 *
 * The four scope fields are all optional **and that is the whole mechanism**: leaving them all blank sets the company
 * default, naming a trade sets that trade's rate, naming a job and a trade sets the rate for that trade on that job.
 * §7.2's ladder then resolves the most specific row that does not contradict the question.
 */
class LabourRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What this rate is for')
                    ->description('Leave everything blank for the company default. Each field you fill in makes the rate more specific, and the most specific one that fits wins: job and trade, then job, then the person, then the trade, then the default.')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->placeholder('Any job')
                            // Job beats person, which surprises people, so it is said here rather than only in §7.2.
                            ->helperText('A job rate applies to everybody on that site — including people who carry their own rate elsewhere. That is what a site allowance is.'),

                        Select::make('trade_id')
                            ->label('Trade')
                            ->options(fn (): array => Trade::query()->active()->orderBy('sort')->orderBy('code')->get()
                                ->mapWithKeys(fn (Trade $trade): array => [$trade->getKey() => $trade->displayName()])
                                ->all())
                            ->searchable()
                            ->placeholder('Any trade'),

                        Select::make('worker_id')
                            ->label('Worker')
                            ->options(fn (): array => Worker::query()->active()->orderBy('code')->get()
                                ->mapWithKeys(fn (Worker $worker): array => [$worker->getKey() => $worker->displayName()])
                                ->all())
                            ->searchable()
                            ->placeholder('Anybody'),

                        Select::make('employee_id')
                            ->label('Employee')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search($search))
                            ->getOptionLabelUsing(fn ($value): ?string => EmployeeOptions::labelFor($value))
                            ->visible(fn (): bool => modules()->enabled('employees'))
                            ->helperText('For somebody on the payroll who has no worker record of their own.'),
                    ]),

                Section::make('The rate')
                    ->columns(3)
                    ->schema([
                        TextInput::make('cost_rate_per_hour')
                            ->label('Cost per hour')
                            ->numeric()
                            ->required()
                            ->helperText('What the hour costs this company, not what it is billed at. Billing has its own ladder in Timesheets.'),

                        TextInput::make('overtime_multiplier')
                            ->label('Overtime multiplier')
                            ->numeric()
                            // Nullable on purpose: a job row can revise the rate and leave the company's overtime
                            // terms alone, because the three figures resolve independently down the ladder.
                            ->helperText('Blank falls through to the next rate that sets one, then to the company default of 1.5.'),

                        TextInput::make('burden_percent')
                            ->label('Burden %')
                            ->numeric()
                            ->helperText('Statutory and welfare cost on top. Blank falls through the same way.'),
                    ]),

                Section::make('When it applies')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label('From')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->helperText('A rate never restates cost already booked: labour records freeze the rate they were approved at.'),

                        DatePicker::make('effective_to')
                            ->label('Until')
                            ->native(false)
                            ->helperText('Blank means "until further notice", which is the ordinary state of the current rate. Two rates for the same scope may not overlap.'),

                        TextInput::make('notes')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Why the rate is what it is — the agreement, the revision, the allowance.'),
                    ]),
            ]);
    }
}
