<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DailyLog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The day's header.
 *
 * **The weather section is a claim form, not small talk.** §16.1: `working_conditions` and `weather_hours_lost` — "those
 * last two, not the free text, are what a weather-based extension of time is actually made of". So they are required
 * fields with helper text saying why, and the prose sits underneath them.
 */
class DailyLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The day')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label(__('Job'))
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->disabled(fn (?DailyLog $record): bool => $record !== null)
                            ->dehydrated(),

                        DatePicker::make('log_date')
                            ->label(__('Date'))
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->disabled(fn (?DailyLog $record): bool => $record !== null)
                            ->dehydrated()
                            // One diary per day per job, and the constraint is the feature (§16.1).
                            ->helperText('One diary per job per day. A second one for the same date is refused — two diaries for one day is how a dispute starts.'),
                    ]),

                Section::make('Weather and working conditions')
                    ->description('The two fields below the weather are what a weather-based extension of time is actually made of. A paragraph about rain cannot be assessed; four hours against a stopped day can.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('weather_am')->label(__('Weather, morning'))->maxLength(255),
                        TextInput::make('weather_pm')->label(__('Weather, afternoon'))->maxLength(255),
                        TextInput::make('temperature_c')->label(__('Temperature °C'))->numeric(),
                        TextInput::make('rainfall_mm')->label(__('Rainfall mm'))->numeric(),
                        TextInput::make('wind_kph')->label(__('Wind kph'))->numeric(),

                        Select::make('working_conditions')
                            ->label(__('Conditions'))
                            ->options(DailyLog::CONDITIONS)
                            ->default(DailyLog::CONDITION_WORKABLE)
                            ->selectablePlaceholder(false)
                            ->required(),

                        TextInput::make('weather_hours_lost')
                            ->label(__('Hours lost to weather'))
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText('Zero on a normal day. This is the number an assessor works from.'),
                    ]),

                Section::make('What happened')
                    ->schema([
                        Textarea::make('work_summary')->label(__('Work done'))->rows(3),
                        Textarea::make('delays')->label(__('Delays'))->rows(2),
                        Textarea::make('instructions_received')->label(__('Instructions received'))->rows(2)
                            ->helperText('A verbal instruction written down on the day is worth more than one remembered six months later.'),
                        Textarea::make('visitors')->rows(2),
                    ]),

                Section::make('Observations')
                    ->description('Safety, quality and environment. Written here on the day; an NCR or an incident report is a separate document when one is needed.')
                    ->columns(3)
                    ->schema([
                        Textarea::make('safety_observations')->label(__('Safety'))->rows(2),
                        Textarea::make('quality_observations')->label(__('Quality'))->rows(2),
                        Textarea::make('environmental_observations')->label(__('Environment'))->rows(2),
                    ]),
            ]);
    }
}
