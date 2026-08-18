<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\JobBudget;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class JobBudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    // Moving a budget to another job would take its lines and any measurement frozen against it
                    // with it, and neither belongs there.
                    ->disabled(fn (?JobBudget $record): bool => $record !== null)
                    ->dehydrated(),

                TextInput::make('name')
                    ->label('Version name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('What this version is: Tender, Contract award, Rev 3 post VO-12.'),

                Select::make('kind')
                    ->label('Kind')
                    ->options([
                        JobBudget::KIND_ESTIMATE => 'Estimate',
                        JobBudget::KIND_ORIGINAL => 'Original budget',
                        JobBudget::KIND_REVISION => 'Revision',
                    ])
                    ->default(JobBudget::KIND_ESTIMATE)
                    ->required(),

                DatePicker::make('effective_from')
                    ->label('Effective from')
                    ->helperText('Left blank, approval fills it in with the day it was approved.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('What changed and why — read by whoever compares this version with the last one.'),
            ]);
    }
}
