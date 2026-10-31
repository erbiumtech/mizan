<?php

namespace App\Modules\Lifecycle\Filament\Settings;

use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Services\ChecklistService;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

/**
 * Which onboarding template a joiner gets — docs/saas-launch-plan.md §14.5.
 *
 * With one active template there is nothing to choose. With several, a joiner used to get none and a
 * warning; a company that runs an office list and a site list now names the one that starts on its own,
 * and Start checklist on the employee is how the other is chosen for somebody.
 */
class OnboardingSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'lifecycle.onboarding';
    }

    public function components(): array
    {
        return [
            Section::make('Onboarding')
                ->description('The checklist a new joiner starts with.')
                ->schema([
                    Select::make('lifecycle_onboarding_template_id')
                        ->label(__('Default onboarding template'))
                        ->options(fn (): array => ChecklistTemplate::query()->active()->ofKind(ChecklistTemplate::KIND_ONBOARDING)->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('Only when there is a single active template')
                        ->nullable()
                        ->helperText('Started for every new joiner. Left blank, a joiner gets the onboarding template only while exactly '
                            .'one is active; with several, none starts and you are told to choose. Start checklist on an employee\'s '
                            .'page starts any template for anybody, whatever is set here.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['lifecycle_onboarding_template_id' => setting(ChecklistService::ONBOARDING_TEMPLATE_SETTING)];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('lifecycle_onboarding_template_id', $state)) {
            return;
        }

        $id = $state['lifecycle_onboarding_template_id'];

        app(TenantSettings::class)->set(ChecklistService::ONBOARDING_TEMPLATE_SETTING, filled($id) ? (int) $id : null);
    }
}
