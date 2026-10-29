<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
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
            'export' => $company ? $this->latestExport($company) : null,
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
                ->label('Export my data')
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
            Action::make('customDomain')
                ->label('Custom domain')
                ->icon('heroicon-o-globe-alt')
                ->visible(fn (): bool => Plans::allowsCustomDomain(static::company()?->plan))
                ->modalHeading('Your own domain')
                ->modalDescription('Create a CNAME record pointing your host at '.CustomDomains::cnameTarget()
                    .', wait for it to propagate, then save it here. The domain is checked before it is accepted. Leave it empty to go back to the platform address.')
                ->modalSubmitActionLabel('Save domain')
                ->schema([
                    TextInput::make('domain')
                        ->label('Domain')
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
                ->label('Subscribe')
                ->icon('heroicon-o-credit-card')
                ->visible(fn (): bool => app(Subscriptions::class)->isConfigured()
                    && (static::company()?->hasPlan() ?? false)
                    && static::company()?->billing_contact_id === null)
                ->modalHeading('Subscribe to '.Plans::label(static::company()?->plan))
                ->modalDescription('You will be invoiced per active employee, by bank transfer. The first invoice is raised on the 1st of next month.')
                ->modalSubmitActionLabel('Subscribe')
                ->schema([
                    TextInput::make('email')
                        ->label('Billing email')
                        ->email()
                        ->required()
                        ->default(fn (): ?string => auth()->user()?->email),
                    TextInput::make('address')
                        ->label('Billing address')
                        ->required()
                        ->maxLength(190),
                    Select::make('country')
                        ->label('Billing country')
                        ->options([$home => 'Pakistan', 'OTHER' => 'Outside Pakistan'])
                        ->default($home)
                        ->required()
                        ->live(),
                    Select::make('currency')
                        ->label('Currency')
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
