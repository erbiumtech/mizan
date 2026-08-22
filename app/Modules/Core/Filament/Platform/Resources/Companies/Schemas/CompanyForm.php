<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Schemas;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\CompanyProfiles;
use App\Support\Modules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
            // the type decides which chart of accounts and which spending
            // categories are seeded, both of which happen once. Switching
            // afterwards would leave a household chart labelled as a business.
            Select::make('type')
                ->label('Type')
                ->options(Company::TYPE_LABELS)
                ->default(Company::TYPE_BUSINESS)
                ->required()
                ->selectablePlaceholder(false)
                ->live()
                // The profile belongs to a type, so a type change invalidates
                // whatever was chosen under the old one. Cleared rather than
                // left to the options list: a stale value that is no longer an
                // option still submits, and the provisioner would refuse it.
                ->afterStateUpdated(fn ($state, Set $set) => $set(
                    'profile',
                    CompanyProfiles::defaultForType((string) $state),
                ))
                ->helperText(fn (?Company $record): string => $record !== null
                    ? 'Fixed once created — the chart of accounts follows from it.'
                    : 'A company gets the trading chart, banks and transaction types. '
                        .'A personal account gets a household chart (Food, Rent, Education, Domestic Staff Wages) '
                        .'and the individual tax estimate.')
                ->disabled(fn (?Company $record): bool => $record !== null)
                ->dehydrated(fn (?Company $record): bool => $record === null),

            // What shape of business this is. Unlike the type this stays
            // editable: it decides the starting licences and the baseline at
            // creation, and then goes on answering "what is recommended for this
            // company" for the rest of its life. Editing it never re-seeds — see
            // docs/company-profiles-plan.md §9.
            Select::make('profile')
                ->label('Profile')
                ->options(fn (Get $get, ?Company $record): array => CompanyProfiles::optionsForType(
                    $record?->type ?? (string) ($get('type') ?? Company::TYPE_BUSINESS),
                ))
                // Optional on purpose. Every company created before profiles
                // existed has none, and "licensed by hand from the registry
                // defaults" stays a supported answer rather than a gap to be
                // backfilled with a guess.
                ->placeholder('None — license by hand')
                ->live()
                ->helperText(fn (Get $get, ?Company $record): string => static::profileHelp(
                    $get('profile'),
                    $record !== null,
                )),

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
            //
            // Split in two where the company has a profile. The profile does not
            // restrict anything — anything here can be granted to anyone — but
            // ordering the twelve toggles by what this kind of business actually
            // uses is the difference between a list and an answer.
            Section::make(fn (?Company $record): string => $record?->hasProfile()
                ? 'Recommended for '.$record->profileLabel()
                : 'Licensed modules')
                ->description(fn (?Company $record): string => $record?->hasProfile()
                    ? 'What this kind of business usually runs. A recommendation, not a restriction.'
                    : 'Modules this company has bought. Revoking one hides it immediately but keeps their own on/off choice, so re-granting restores what they had. Core is always included.')
                ->visible(fn (?Company $record) => $record !== null && (auth()->user()?->isSuperAdmin() ?? false))
                ->columns(2)
                ->schema(fn (?Company $record): array => static::toggles(
                    static::recommendedFor($record),
                )),

            Section::make('Other modules')
                ->description('Outside this profile. Grant anything here that has been sold — nothing about a profile prevents it.')
                // Absent rather than empty when there is no profile: the section
                // above is then already the whole list.
                ->visible(fn (?Company $record) => $record !== null
                    && $record->hasProfile()
                    && (auth()->user()?->isSuperAdmin() ?? false))
                ->columns(2)
                ->collapsed()
                ->schema(fn (?Company $record): array => static::toggles(
                    array_values(array_diff(static::licensableModules(), static::recommendedFor($record))),
                )),
        ]);
    }

    /**
     * What the profile recommends, or every licensable module when there is no
     * profile — in which case the first section is the whole list.
     *
     * @return array<int, string>
     */
    private static function recommendedFor(?Company $record): array
    {
        if (! $record?->hasProfile()) {
            return static::licensableModules();
        }

        // Intersected with the registry rather than trusted, so a profile naming
        // a module that has since been removed drops a toggle instead of
        // rendering one that saves nowhere.
        return array_values(array_intersect(
            static::licensableModules(),
            CompanyProfiles::modules($record->profile) ?? [],
        ));
    }

    /** @return array<int, string> */
    private static function licensableModules(): array
    {
        return array_values(array_filter(
            Modules::names(),
            fn (string $module): bool => ! Modules::isLocked($module),
        ));
    }

    /**
     * @param  array<int, string>  $modules
     * @return array<int, Toggle>
     */
    private static function toggles(array $modules): array
    {
        return array_map(
            fn (string $module): Toggle => Toggle::make("modules.{$module}")
                ->label(Modules::label($module))
                ->helperText(config("modules.{$module}.description")),
            $modules,
        );
    }

    /**
     * What choosing this profile will do — spelled out before the save, because
     * at creation it is irreversible in one respect: the baseline is seeded once.
     */
    private static function profileHelp(mixed $profile, bool $existing): string
    {
        $profile = is_string($profile) && $profile !== '' ? $profile : null;

        if ($profile === null) {
            return $existing
                ? 'No profile. Every module is listed together below, in registry order.'
                : 'Optional. Without one the company starts with Core only and you grant the rest by hand.';
        }

        $modules = implode(', ', array_map(
            fn (string $module): string => Modules::label($module),
            CompanyProfiles::modules($profile) ?? [],
        ));

        if ($existing) {
            return CompanyProfiles::description($profile)
                .' Changing this re-scopes what is recommended below; it does not re-seed the company '
                .'or change a single licence. Use "Apply profile licences" for that.';
        }

        return CompanyProfiles::description($profile).' Licenses: '.$modules.'.';
    }
}
