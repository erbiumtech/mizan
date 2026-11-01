<?php

namespace App\Modules\Construction\Filament\Resources\Jobs\Schemas;

use App\Modules\Construction\Models\Job;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A job read in the list's view sheet, in the form's own sections — docs/side-sheets.md. Fields that
 * belong to another module (client, certifier, site store, exposure hours, percent complete) show only
 * when that module is on, as on the form.
 */
class JobInfolist
{
    private const NATURES = [
        'building' => 'Building',
        'civils' => 'Civils',
        'infrastructure' => 'Infrastructure',
        'fit_out' => 'Fit-out',
        'mep' => 'MEP',
        'marine' => 'Marine',
        'other' => 'Other',
    ];

    private const STATUSES = [
        'tender' => 'Tender',
        'awarded' => 'Awarded',
        'mobilising' => 'Mobilising',
        'in_progress' => 'In progress',
        'suspended' => 'Suspended',
        'substantial_completion' => 'Substantial completion',
        'defects_liability' => 'Defects liability',
        'final_account' => 'Final account',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
        'lost' => 'Lost',
    ];

    private const STANDARDS = [
        Job::STANDARD_FIDIC => 'FIDIC',
        Job::STANDARD_AIA => 'AIA',
        Job::STANDARD_CUSTOM => 'Custom',
    ];

    private const EXPOSURE_SOURCES = [
        'daily_log' => 'The site diary — approved manpower returns',
        'timesheets' => 'Timesheets — hours booked to the job',
    ];

    private const PERCENT_COMPLETE_METHODS = [
        'cost_to_cost' => 'Cost to cost — cost to date over forecast final cost',
        'surveyed' => 'Surveyed — measured progress against the budget',
        'milestone' => 'Milestone — certified milestone value',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The job'))
                ->columns(2)
                ->schema([
                    TextEntry::make('code')->label(__('Job number'))->copyable(),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('parent.name')->label(__('Part of'))->placeholder('—'),
                    TextEntry::make('nature')
                        ->label(__('Nature'))
                        ->formatStateUsing(fn (string $state): string => __(self::NATURES[$state] ?? $state)),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'in_progress' => 'success',
                            'suspended', 'cancelled', 'lost' => 'danger',
                            'tender', 'closed' => 'gray',
                            default => 'warning',
                        })
                        ->formatStateUsing(fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                    TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
                ]),

            Section::make(__('The contract'))
                ->columns(2)
                ->schema([
                    TextEntry::make('contract_standard')
                        ->label(__('Contract standard'))
                        ->formatStateUsing(fn (string $state): string => __(self::STANDARDS[$state] ?? $state)),
                    TextEntry::make('client.name')
                        ->label(__('Client'))
                        ->placeholder('—')
                        ->visible(fn (): bool => modules()->enabled('invoicing')),
                    TextEntry::make('stockLocation.name')
                        ->label(__('Site store'))
                        ->formatStateUsing(fn ($state, Job $record): string => $record->stockLocation?->displayName() ?? (string) $state)
                        ->placeholder(__('No store — everything direct to the work face'))
                        ->visible(fn (): bool => modules()->enabled('inventory')),
                    TextEntry::make('exposure_hours_source')
                        ->label(__('Safety exposure hours from'))
                        ->formatStateUsing(fn (string $state): string => __(self::EXPOSURE_SOURCES[$state] ?? $state))
                        ->placeholder(__('Not chosen — safety rates will not be computed'))
                        ->visible(fn (): bool => modules()->enabled('construction_qhse')
                            && (modules()->enabled('construction_field') || modules()->enabled('timesheets'))),
                    TextEntry::make('percent_complete_method')
                        ->label(__('Percent complete measured by'))
                        ->formatStateUsing(fn (string $state): string => __(self::PERCENT_COMPLETE_METHODS[$state] ?? $state))
                        ->placeholder(__('Not chosen — no work-in-progress position will be computed'))
                        ->visible(fn (): bool => modules()->enabled('construction_costing')),
                    TextEntry::make('certifier.name')
                        ->label(__('Certifier'))
                        ->placeholder('—')
                        ->visible(fn (): bool => modules()->enabled('invoicing')),
                    TextEntry::make('contract_sum')
                        ->label(__('Contract sum (original)'))
                        ->money(fn (Job $record): string => $record->currency_code ?: CompanyCurrency::code())
                        ->placeholder('—'),
                ]),

            Section::make(__('The site'))
                ->columns(2)
                ->schema([
                    TextEntry::make('site_address_line_1')->label(__('Address'))->placeholder('—'),
                    TextEntry::make('site_address_line_2')->label(__('Address line 2'))->placeholder('—'),
                    TextEntry::make('site_city')->label(__('City'))->placeholder('—'),
                    TextEntry::make('site_country')->label(__('Country'))->placeholder('—'),
                ]),

            Section::make(__('Programme'))
                ->columns(2)
                ->schema([
                    TextEntry::make('commencement_date')->label(__('Commencement date'))->date()->placeholder('—'),
                    TextEntry::make('planned_completion_date')->label(__('Planned completion date'))->date()->placeholder('—'),
                    TextEntry::make('revised_completion_date')->label(__('Revised completion date'))->date()->placeholder('—'),
                    TextEntry::make('defects_period_days')->label(__('Defects liability (days)'))->placeholder('—'),
                ]),
        ]);
    }
}
