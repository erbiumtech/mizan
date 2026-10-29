<?php

namespace App\Modules\Recruitment\Filament\Settings;

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
 * The careers page's two gates — docs/saas-launch-plan.md §7.4. Copied from the
 * lead-capture section: a toggle and a token, both read by the middleware, so a
 * leaked address can be closed without a deploy.
 */
class CareersSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'recruitment.careers';
    }

    public function components(): array
    {
        return [
            Section::make('Careers page')
                ->description('A public page listing every open vacancy, with an application form. Each '
                    .'submission becomes an applicant and an application at the "applied" stage; nothing '
                    .'moves further without a person.')
                ->visible(fn (): bool => modules()->enabled('recruitment'))
                ->schema([
                    Toggle::make('recruitment_careers_enabled')
                        ->label('Publish the careers page')
                        ->helperText('Off by default. The page is reachable only with the token below, and switching this off closes it without changing the address.'),
                    TextInput::make('recruitment_careers_token')
                        ->label('Page token')
                        ->helperText('Part of the address. Changing it breaks every link already shared.')
                        ->suffixAction(
                            Action::make('generateCareersToken')
                                ->icon('heroicon-m-arrow-path')
                                ->label('Generate')
                                ->action(fn (Set $set) => $set('recruitment_careers_token', Str::random(40)))
                        ),
                    Placeholder::make('recruitment_careers_url')
                        ->label('Careers page address')
                        ->content(function (Get $get): string {
                            $token = $get('recruitment_careers_token');
                            $company = Company::current();

                            if (! $token || ! $company) {
                                return 'Generate a token to get the address.';
                            }

                            return route('recruitment.careers.index', ['company' => $company->slug, 'token' => $token]);
                        }),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'recruitment_careers_enabled' => (bool) setting('recruitment.careers.enabled', false),
            'recruitment_careers_token' => setting('recruitment.careers.token'),
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('recruitment_careers_enabled', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);
        $settings->set('recruitment.careers.enabled', (bool) ($state['recruitment_careers_enabled'] ?? false));
        $settings->set('recruitment.careers.token', $state['recruitment_careers_token'] ?: null);
    }
}
