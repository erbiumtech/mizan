<?php

namespace App\Modules\Recruitment\Filament\Resources\Vacancies\Schemas;

use App\Modules\Recruitment\Models\Vacancy;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * A vacancy read in the list's view sheet — docs/side-sheets.md. Department, designation and
 * employment type read through the same option lists the form offers.
 */
class VacancyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('code')->label(__('Code'))->copyable(),
            TextEntry::make('title')->label(__('Title')),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    Vacancy::STATUS_OPEN => 'success',
                    Vacancy::STATUS_ON_HOLD => 'warning',
                    Vacancy::STATUS_FILLED => 'info',
                    default => 'gray',
                })
                ->formatStateUsing(fn (string $state): string => __(ucfirst(str_replace('_', ' ', $state)))),
            TextEntry::make('openings')->label(__('Openings')),
            TextEntry::make('department')
                ->label(__('Department'))
                ->formatStateUsing(fn (string $state): string => options('employees.department', $state)[$state] ?? $state)
                ->placeholder('—'),
            TextEntry::make('designation')
                ->label(__('Designation'))
                ->formatStateUsing(fn (string $state): string => options('employees.designation', $state)[$state] ?? $state)
                ->placeholder('—'),
            TextEntry::make('employment_type')
                ->label(__('Employment type'))
                ->formatStateUsing(fn (string $state): string => options('employees.employment_type', $state)[$state] ?? $state)
                ->placeholder(__('Not stated')),
            TextEntry::make('hiringManager.display_label')
                ->label(__('Hiring manager'))
                ->placeholder('—')
                ->visible(fn (): bool => modules()->enabled('employees')),
            TextEntry::make('salary_min')->label(__('Salary from'))->money(CompanyCurrency::code())->placeholder('—'),
            TextEntry::make('salary_max')->label(__('Salary to'))->money(CompanyCurrency::code())->placeholder('—'),
            TextEntry::make('opened_on')->label(__('Opened'))->date()->placeholder('—'),
            TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
