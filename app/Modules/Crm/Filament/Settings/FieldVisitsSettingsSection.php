<?php

namespace App\Modules\Crm\Filament\Settings;

use App\Modules\Crm\Services\FieldVisits;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

                    /*
                     * Judging where a check-in happened — docs/saas-launch-plan.md §14.10.
                     *
                     * The same three the attendance geofence offers, and the middle one is
                     * the default for the same reason: a company that has not thought about
                     * it wants to see the problem, not have its reps locked out of their
                     * own calls on a day the signal is poor.
                     */
                    Select::make('crm_field_visits_geofence_mode')
                        ->label('Checking the position')
                        ->options(FieldVisits::MODES)
                        ->default(FieldVisits::MODE_FLAG)
                        ->required()
                        ->helperText('A visit is judged against the pin somebody dropped on the client. Nothing is '
                            .'judged until a place has a pin, whatever this says.'),

                    TextInput::make('crm_field_visits_accuracy_limit_m')
                        ->label('Accept a reading within (metres)')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(2000)
                        ->helperText('A phone reports how sure it is. A reading vaguer than this is recorded as '
                            .'"could not tell" rather than being called away — indoors, that is most of them.'),

                    Toggle::make('crm_field_visits_enrolment_required')
                        ->label('Only enrolled staff may be sent on visits')
                        ->helperText('Off by default, when anybody can be named on a visit. On, the picker offers '
                            .'only the people enrolled under Field Visit Staff.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'crm_field_visits_enabled' => (bool) setting('crm.field_visits.enabled', config('crm.field_visits.enabled', false)),
            'crm_field_visits_geofence_mode' => app(FieldVisits::class)->mode(),
            'crm_field_visits_accuracy_limit_m' => app(FieldVisits::class)->accuracyLimit(),
            'crm_field_visits_enrolment_required' => (bool) setting('crm.field_visits.enrolment_required', false),
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('crm_field_visits_enabled', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);

        $settings->set('crm.field_visits.enabled', (bool) ($state['crm_field_visits_enabled'] ?? false));
        $settings->set('crm.field_visits.geofence_mode', array_key_exists((string) ($state['crm_field_visits_geofence_mode'] ?? ''), FieldVisits::MODES)
            ? $state['crm_field_visits_geofence_mode']
            : FieldVisits::MODE_FLAG);
        $settings->set('crm.field_visits.accuracy_limit_m', max(10, (int) ($state['crm_field_visits_accuracy_limit_m'] ?: 100)));
        $settings->set('crm.field_visits.enrolment_required', (bool) ($state['crm_field_visits_enrolment_required'] ?? false));
    }
}
