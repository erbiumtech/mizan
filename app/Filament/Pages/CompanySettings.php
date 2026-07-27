<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Support\TenantSettings;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Per-company (per-tenant) settings editor. Loads the effective values via the
 * TenantSettings accessor (tenant override or config default) and persists
 * overrides into the current tenant's `settings` table.
 */
class CompanySettings extends Page
{
    protected string $view = 'filament.pages.company-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'Company Settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Administrator') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'petty_cash_float_amount' => setting('petty_cash.float_amount'),
            'accounting_auto_post_payroll' => (bool) setting('accounting.auto_post_payroll'),
            'accounting_payroll_accounts' => setting('accounting.payroll_accounts'),
            'ipayments' => setting('ipayments'),
            'projects_status_page_enabled' => (bool) setting('projects.status_page.enabled', false),
            'projects_status_page_token' => setting('projects.status_page.token'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Petty Cash')
                    ->schema([
                        TextInput::make('petty_cash_float_amount')
                            ->label('Float Amount')
                            ->numeric()
                            ->required()
                            ->helperText('The imprest the petty cash box is restored to each month.'),
                    ]),

                Section::make('Payroll')
                    ->schema([
                        Toggle::make('accounting_auto_post_payroll')
                            ->label('Auto-post payroll journal entries')
                            ->helperText('When on, payroll entries are approved and posted on creation; otherwise they await Manager/CEO approval.'),

                        KeyValue::make('accounting_payroll_accounts')
                            ->label('Payroll Account Codes')
                            ->keyLabel('Line')
                            ->valueLabel('Account code')
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false),
                    ]),

                Section::make('iPayments (Salary Bank File)')
                    ->schema([
                        KeyValue::make('ipayments')
                            ->label('iPayments Defaults')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false),
                    ]),

                Section::make('Public Status Page')
                    ->description('Publishes the up/down state and uptime of environments marked "Show on public status page". Never publishes URLs, credentials or error details.')
                    ->schema([
                        Toggle::make('projects_status_page_enabled')
                            ->label('Enable public status page')
                            ->helperText('Off by default. The page is reachable only with the token below.'),

                        TextInput::make('projects_status_page_token')
                            ->label('Access token')
                            ->helperText('Part of the URL. Changing it revokes every link already shared.')
                            ->suffixAction(
                                \Filament\Actions\Action::make('generateStatusToken')
                                    ->icon('heroicon-m-arrow-path')
                                    ->label('Generate')
                                    ->action(fn (Set $set) => $set('projects_status_page_token', Str::random(40)))
                            ),

                        Placeholder::make('status_page_url')
                            ->label('Status page URL')
                            ->content(function (Get $get): string {
                                $token = $get('projects_status_page_token');
                                $company = Company::current();

                                if (! $token || ! $company) {
                                    return 'Generate a token to get the URL.';
                                }

                                return route('status.show', ['company' => $company->slug, 'token' => $token]);
                            }),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(TenantSettings::class);
        $settings->set('petty_cash.float_amount', (float) $state['petty_cash_float_amount']);
        $settings->set('accounting.auto_post_payroll', (bool) $state['accounting_auto_post_payroll']);
        $settings->set('accounting.payroll_accounts', $state['accounting_payroll_accounts']);
        $settings->set('ipayments', $state['ipayments']);
        $settings->set('projects.status_page.enabled', (bool) ($state['projects_status_page_enabled'] ?? false));
        $settings->set('projects.status_page.token', $state['projects_status_page_token'] ?: null);

        // A stale cached payload would otherwise keep serving after the page is
        // switched off or its token rotated.
        if (Company::current()) {
            \Illuminate\Support\Facades\Cache::forget('status-page:'.Company::current()->getKey());
        }

        Notification::make()->title('Settings saved.')->success()->send();
    }
}
