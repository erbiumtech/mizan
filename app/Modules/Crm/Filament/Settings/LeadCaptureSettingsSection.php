<?php

namespace App\Modules\Crm\Filament\Settings;

use App\Modules\Core\Models\Company;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * The switch and token for the public lead-capture endpoint.
 *
 * `LeadCaptureController` and its middleware shipped with phase 3.5, but the two settings they
 * read — `crm.lead_capture.enabled` and `crm.lead_capture.token` — had no writer: the endpoint
 * existed and no admin could open it. This is the missing writer, in the exact shape of the
 * status-page section it was modelled on: a toggle, a token with a generate action, and the
 * resulting URL to copy.
 */
class LeadCaptureSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'crm.lead-capture';
    }

    public function components(): array
    {
        return [
            Section::make('Lead capture')
                ->description('A public address your website form, landing page or Zapier can POST to; each '
                    .'submission becomes a lead with the "Web form" source, deduplicated against what is '
                    .'already in the register. Accepted fields: company_name, person_name, title, email, '
                    .'phone, whatsapp, city, notes — anything else is ignored.')
                ->visible(fn (): bool => modules()->enabled('crm'))
                ->schema([
                    Toggle::make('crm_lead_capture_enabled')
                        ->label('Enable lead capture')
                        ->helperText('Off by default. The endpoint is reachable only with the token below, '
                            .'and switching this off closes it without changing the token.'),

                    TextInput::make('crm_lead_capture_token')
                        ->label('Capture token')
                        ->helperText('Part of the URL. Changing it revokes every form and integration already pointed here.')
                        ->suffixAction(
                            Action::make('generateLeadCaptureToken')
                                ->icon('heroicon-m-arrow-path')
                                ->label('Generate')
                                ->action(fn (Set $set) => $set('crm_lead_capture_token', Str::random(40)))
                        ),

                    Placeholder::make('crm_lead_capture_url')
                        ->label('Capture URL')
                        ->content(function (Get $get): string {
                            $token = $get('crm_lead_capture_token');
                            $company = Company::current();

                            if (! $token || ! $company) {
                                return 'Generate a token to get the URL.';
                            }

                            return route('crm.leads.capture', ['company' => $company->slug, 'token' => $token]);
                        }),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'crm_lead_capture_enabled' => (bool) setting('crm.lead_capture.enabled', false),
            'crm_lead_capture_token' => setting('crm.lead_capture.token'),
        ];
    }

    public function save(array $state): void
    {
        // Absent when visible() hid the section — a company without CRM must not have
        // its (unreachable) capture settings overwritten by saving this page.
        if (! array_key_exists('crm_lead_capture_enabled', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);
        $settings->set('crm.lead_capture.enabled', (bool) ($state['crm_lead_capture_enabled'] ?? false));
        $settings->set('crm.lead_capture.token', $state['crm_lead_capture_token'] ?: null);
    }
}
