<?php

namespace App\Modules\Core\Filament\Pages;

use App\Backup\TenantBackup;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Jobs\BackUpTenant;
use App\Modules\Core\Jobs\ExportTenant;
use App\Modules\Core\Models\Company;
use App\Support\CustomDomains;
use App\Support\Plans;
use App\Support\Subscriptions;
use App\Support\TenantExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;
use UnitEnum;

/**
 * What this company pays for, and the one action that starts paying — docs/saas-launch-plan.md §6.
 *
 * Landlord data on a tenant page: the plan, the seats, the trial end and the
 * billing currency are columns on `companies`, so this is a Page rather than a
 * SettingsSections entry. Administrator only, like Company Settings.
 */
class Subscription extends Page
{
    protected string $view = 'filament.pages.subscription';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $title = 'Subscription';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    /**
     * Shown to every company, plan or not: the export (plan §9) is every
     * company's, and a company licensed by hand reads "licensed by hand" here
     * rather than finding no page. Hiding it also tripped ReportsHubTest, which
     * treats every page off the sidebar as a report.
     */
    public static function company(): ?Company
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company ? $tenant : null;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $company = static::company();

        return [
            'plan' => Plans::label($company?->plan),
            'seats' => $company?->seats,
            'countedAt' => $company?->seats_counted_at?->toDayDateTimeString(),
            'trialEndsAt' => $company?->trial_ends_at?->toFormattedDateString(),
            'subscribed' => $company?->billing_contact_id !== null,
            'currency' => $company?->billing_currency,
            'minimumSeats' => Plans::minimumSeats(),
            'price' => $company?->billing_currency && $company->plan
                ? Plans::price($company->plan, $company->billing_currency)
                : null,
            'operator' => app(Subscriptions::class)->isConfigured()
                ? app(Subscriptions::class)->operator()->name
                : null,
            'reservedSeats' => (int) ($company?->seats_reserved ?? 0),
            'export' => $company ? $this->latestExport($company) : null,
            // The snapshots this company has — docs/saas-launch-plan.md §14.8.
            'snapshots' => $company ? $this->snapshots($company) : [],
            // A company's own host — docs/saas-launch-plan.md §8.8.
            'domain' => $company?->domain,
            'customDomainAllowed' => Plans::allowsCustomDomain($company?->plan),
            'cnameTarget' => CustomDomains::cnameTarget(),
        ];
    }

    /** @return array{path: string, at: string, url: string, bytes: int}|null */
    private function latestExport(Company $company): ?array
    {
        $latest = app(TenantExport::class)->latest($company);

        return $latest === null ? null : [
            'path' => $latest['path'],
            'at' => $latest['created_at']->toDayDateTimeString(),
            'url' => app(TenantExport::class)->url($company, $latest['path']),
            'bytes' => $latest['bytes'],
        ];
    }

    /**
     * The last fortnight of database snapshots, newest first.
     *
     * Listed and not offered for download, deliberately. An archive is the whole
     * company's database in one file, it is encrypted with a key that lives in the
     * environment rather than in anybody's hands, and restoring one is an operator's
     * job. What this page is for is the other question: *is there a recent one*.
     *
     * @return array<int, array{at: string, size: string}>
     */
    private function snapshots(Company $company): array
    {
        return collect(app(TenantBackup::class)->snapshots($company))
            ->map(fn (array $snapshot): array => [
                'at' => $snapshot['date']->toDayDateTimeString(),
                'size' => $snapshot['bytes'] >= 1048576
                    ? round($snapshot['bytes'] / 1048576, 1).' MB'
                    : max(1, (int) round($snapshot['bytes'] / 1024)).' KB',
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        $home = (string) config('saas.home_country', 'PK');
        $foreign = array_values(array_diff(Plans::currencies(), [(string) config('saas.home_currency', 'PKR')]));

        return [
            HelpAction::make('subscription', 'Subscription: Help'),
            /**
             * The 90-day promise — plan §9. Everything, as one archive, in the
             * company's own storage; the person who asked is emailed the link.
             */
            Action::make('export')
                ->label(__('Export my data'))
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Export all company data')
                ->modalDescription('The whole database as SQL and every uploaded file, in one archive. It takes a few minutes; you will get an email with the link, and the archive appears on this page.')
                ->modalSubmitActionLabel('Start the export')
                ->action(function (): void {
                    ExportTenant::dispatch((int) static::company()->getKey(), (int) auth()->id());

                    Notification::make()->success()
                        ->title('Export started')
                        ->body('You will get an email when it is ready.')
                        ->send();
                }),
            /**
             * A snapshot on demand — plan §14.8. The same dump the nightly run makes,
             * for the moment before a bulk import or a payroll run somebody is nervous
             * about.
             */
            Action::make('backUpNow')
                ->label(__('Back up now'))
                ->icon('heroicon-o-circle-stack')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Take a snapshot now')
                ->modalDescription('A dump of this company\'s database, kept beside the nightly ones. It runs in '
                    .'the background and you will be told when it is done. It does not include uploaded files, '
                    .'which are backed up for every company together.')
                ->modalSubmitActionLabel('Start the backup')
                ->action(function (): void {
                    BackUpTenant::dispatch((int) static::company()->getKey(), (int) auth()->id());

                    Notification::make()->success()
                        ->title('Backup started')
                        ->body('It appears in the list on this page when it finishes.')
                        ->send();
                }),
            /**
             * Changing plan — plan §14.9.
             *
             * `Company::changePlan()` grants the new plan's modules and never revokes, so
             * moving down would leave what was already licensed switched on at the smaller
             * price. Only plans that cover the current one are offered here, and the model
             * refuses the rest: a downgrade is support's (§12.3). The price is not touched
             * here either: the agreement's unit price is the customer's, agreed with a
             * person, and the meter has never written it. Somebody has to edit the
             * agreement, and the modal says so.
             */
            Action::make('changePlan')
                ->label(__('Change plan'))
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->visible(fn (): bool => static::company()?->plan !== null && count(Plans::upgradesFrom(static::company()?->plan)) > 1)
                ->modalHeading('Change plan')
                ->modalDescription('The new plan\'s modules are switched on straight away. Nothing is switched '
                    .'off: a module you already have stays. Only plans that include everything yours does are '
                    .'offered here; to move to a smaller plan, ask support. The price on your agreement does not '
                    .'change by itself — tell us and we will reissue it.')
                ->modalSubmitActionLabel('Change it')
                ->schema([
                    Select::make('plan')
                        ->label(__('Plan'))
                        ->options(Plans::options())
                        ->default(fn (): ?string => static::company()?->plan)
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $company = static::company();

                    if ($company === null) {
                        return;
                    }

                    try {
                        $company->changePlan((string) $data['plan']);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Plan not changed')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('Now on '.Plans::label($data['plan']))
                        ->body('Any new modules are available immediately.')
                        ->send();
                }),

            /**
             * Seats held above headcount — plan §14.9.
             *
             * For a company that is about to hire and does not want its bill to lurch. It
             * raises the floor and never the ceiling: hire thirty into twenty held seats
             * and thirty is billed, so this cannot be used to cap what is owed.
             */
            Action::make('holdSeats')
                ->label(__('Hold seats'))
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->visible(fn (): bool => static::company()?->billing_contact_id !== null)
                ->modalHeading('Hold seats above your headcount')
                ->modalDescription('Your bill counts the greatest of your active employees, the seats you hold, '
                    .'and the plan minimum. Holding seats smooths a month you are hiring into; it never reduces '
                    .'what you are billed when the people arrive.')
                ->modalSubmitActionLabel('Save')
                ->schema([
                    TextInput::make('seats_reserved')
                        ->label(__('Seats held'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(10000)
                        ->default(fn (): int => (int) (static::company()?->seats_reserved ?? 0))
                        ->helperText(fn (): string => 'You have '.(int) (static::company()?->seats ?? 0)
                            .' active employee(s) and the plan minimum is '.Plans::minimumSeats().'.'),
                ])
                ->action(function (array $data): void {
                    $company = static::company();

                    $company?->forceFill(['seats_reserved' => max(0, (int) ($data['seats_reserved'] ?? 0))])->save();

                    Notification::make()->success()
                        ->title('Saved. It applies on the next meter, on the 1st.')
                        ->send();
                }),

            Action::make('customDomain')
                ->label(__('Custom domain'))
                ->icon('heroicon-o-globe-alt')
                ->visible(fn (): bool => Plans::allowsCustomDomain(static::company()?->plan))
                ->modalHeading('Your own domain')
                ->modalDescription('Create a CNAME record pointing your host at '.CustomDomains::cnameTarget()
                    .', wait for it to propagate, then save it here. The domain is checked before it is accepted. Leave it empty to go back to the platform address.')
                ->modalSubmitActionLabel('Save domain')
                ->schema([
                    TextInput::make('domain')
                        ->label(__('Domain'))
                        ->placeholder('hr.yourcompany.pk')
                        ->maxLength(190)
                        ->default(fn (): ?string => static::company()?->domain),
                ])
                ->action(function (array $data): void {
                    try {
                        $company = CustomDomains::claim(static::company(), $data['domain'] ?? null);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Domain not saved')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title($company->domain ? "Your panel answers on {$company->domain}" : 'Back on the platform address')
                        ->body($company->domain ? 'The first visit takes a few seconds while the certificate is issued.' : null)
                        ->send();
                }),

            Action::make('subscribe')
                ->label(__('Subscribe'))
                ->icon('heroicon-o-credit-card')
                ->visible(fn (): bool => app(Subscriptions::class)->isConfigured()
                    && (static::company()?->hasPlan() ?? false)
                    && static::company()?->billing_contact_id === null)
                ->modalHeading('Subscribe to '.Plans::label(static::company()?->plan))
                ->modalDescription('You will be invoiced per active employee, by bank transfer. The first invoice is raised on the 1st of next month.')
                ->modalSubmitActionLabel('Subscribe')
                ->schema([
                    TextInput::make('email')
                        ->label(__('Billing email'))
                        ->email()
                        ->required()
                        ->default(fn (): ?string => auth()->user()?->email),
                    TextInput::make('address')
                        ->label(__('Billing address'))
                        ->required()
                        ->maxLength(190),
                    Select::make('country')
                        ->label(__('Billing country'))
                        ->options([$home => 'Pakistan', 'OTHER' => 'Outside Pakistan'])
                        ->default($home)
                        ->required()
                        ->live(),
                    Select::make('currency')
                        ->label(__('Currency'))
                        ->options(array_combine($foreign, $foreign))
                        ->required()
                        ->visible(fn (Get $get): bool => $get('country') !== $home)
                        ->helperText('Fixed once chosen. Invoices outside Pakistan are quarterly, in advance.'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(Subscriptions::class)->subscribe(
                            static::company(),
                            email: $data['email'],
                            address: $data['address'],
                            country: $data['country'] === 'OTHER' ? 'OTHER' : (string) config('saas.home_country', 'PK'),
                            currency: $data['currency'] ?? null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Not subscribed')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('Subscribed')
                        ->body('Your first invoice will be raised on the 1st of next month and emailed to the billing address.')
                        ->send();
                }),
        ];
    }
}
