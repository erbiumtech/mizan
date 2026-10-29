<?php

namespace App\Modules\Crm\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/** The switch for the public confirmation page — docs/saas-launch-plan.md §8.5. */
class FieldVisitsSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'crm.field-visits';
    }

    public function components(): array
    {
        return [
            Section::make('Field visits')
                ->description('When a rep checks out of a visit, the person visited is sent a link to confirm it happened. '
                    .'The link works for a day and the page shows only the date, the rep and the company.')
                ->visible(fn (): bool => modules()->enabled('crm'))
                ->schema([
                    Toggle::make('crm_field_visits_enabled')
                        ->label('Send confirmation links')
                        ->helperText('Off by default. Switching it off closes every link already sent.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['crm_field_visits_enabled' => (bool) setting('crm.field_visits.enabled', config('crm.field_visits.enabled', false))];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('crm_field_visits_enabled', $state)) {
            return;
        }

        app(TenantSettings::class)->set('crm.field_visits.enabled', (bool) ($state['crm_field_visits_enabled'] ?? false));
    }
}
