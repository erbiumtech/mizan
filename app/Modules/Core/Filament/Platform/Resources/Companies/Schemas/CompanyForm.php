<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Schemas;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\Modules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            // What kind of books these are. The provisioner has taken this since
            // personal accounts were added, and nothing ever passed it — so every
            // company made from this screen came out a business, and a personal
            // account could only be created from tinker.
            //
            // Create-only, and that is a constraint rather than an omission:
            // the type decides which chart of accounts, which spending
            // categories and which modules are seeded, all of which happen once.
            // Switching afterwards would leave a household chart labelled as a
            // business, or a business with no salary slabs.
            Select::make('type')
                ->label('Type')
                ->options(Company::TYPE_LABELS)
                ->default(Company::TYPE_BUSINESS)
                ->required()
                ->selectablePlaceholder(false)
                ->helperText(fn (?Company $record): string => $record !== null
                    ? 'Fixed once created — the chart of accounts and starting modules follow from it.'
                    : 'A company gets the trading chart, banks, transaction types and payroll slabs. '
                        .'A personal account gets a household chart (Food, Rent, Education, Domestic Staff Wages), '
                        .'the individual tax estimate, and no payroll.')
                ->disabled(fn (?Company $record): bool => $record !== null)
                ->dehydrated(fn (?Company $record): bool => $record === null),

            // Only asked on create — the assigned user becomes this company's
            // Administrator (attached + given the Administrator role in its team).
            Select::make('admin_user_id')
                ->label('Company Admin')
                ->helperText('This user is added to the company as its Administrator.')
                // Any user, not just the current company's — see User::scopeAcrossCompanies().
                ->options(fn () => User::acrossCompanies()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->dehydrated()
                ->visible(fn (?Company $record) => $record === null),

            Select::make('status')
                ->options([1 => 'Active', 0 => 'Inactive'])
                ->default(1)
                ->visible(fn (?Company $record) => $record !== null),

            TextInput::make('slug')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (?Company $record) => $record !== null),

            // Licensing: what this company has bought. The company's own
            // Administrator then chooses which of these are switched on, on the
            // tenant-side Modules page — two flags, two owners.
            //
            // Only on edit, and only for super admins: a company admin granting
            // themselves a module would be a billing hole. CompanyResource is
            // already super-admin-only, so this is defence in depth.
            Section::make('Licensed modules')
                ->description('Modules this company has bought. Revoking one hides it immediately but keeps their own on/off choice, so re-granting restores what they had. Core is always included.')
                ->visible(fn (?Company $record) => $record !== null && (auth()->user()?->isSuperAdmin() ?? false))
                ->columns(2)
                ->schema(array_map(
                    fn (string $module) => Toggle::make("modules.{$module}")
                        ->label(Modules::label($module))
                        ->helperText(config("modules.{$module}.description")),
                    array_values(array_filter(Modules::names(), fn (string $m) => ! Modules::isLocked($m))),
                )),
        ]);
    }
}
