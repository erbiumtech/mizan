<?php

namespace App\Modules\Work\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * The work module's company-wide defaults — docs/project-management-plan.md §15's `work.defaults`.
 *
 * Three decisions a company makes once: whether it estimates in points or hours (§16.3), whether the
 * morning digest goes out and when (§10.3), and what a merged pull request does to the items it
 * names (§8.4, read from Phase 7). Each of the last two a project may override on its own settings.
 *
 * Under **Company** rather than People: none of it is employment policy.
 */
class WorkDefaultsSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'work.defaults';
    }

    public function components(): array
    {
        return [
            Section::make('Work')
                ->description('How this company runs its boards. A project may override the merge rule on its own settings.')
                ->columns(2)
                ->schema([
                    Select::make('work.estimation_unit')
                        ->label('Estimate in')
                        ->options(['hours' => 'Hours', 'points' => 'Story points'])
                        ->required()
                        ->native(false)
                        ->helperText('Both are recorded; this is which the forms lead with and the reports sum. Hours, unless the team has run sprints before.'),

                    Toggle::make('work.merge_moves_to_done')
                        ->label('A merged pull request resolves the items it names')
                        ->helperText('Off, a merge moves them to In review instead, if the workflow has one.'),

                    Toggle::make('work.digest_enabled')
                        ->label('Send the morning digest')
                        ->live()
                        ->helperText('Items due today, assigned since yesterday, and blocked on you — one message per person who has any. Anybody may turn the Work category off for themselves.'),

                    TimePicker::make('work.digest_time')
                        ->label('At')
                        ->seconds(false)
                        ->native(false)
                        ->visible(fn ($get): bool => (bool) $get('work.digest_enabled'))
                        ->helperText('In the application\'s timezone. The hour is what counts; the digest runs on the hour.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'work.estimation_unit' => (string) setting('work.estimation_unit', 'hours'),
            'work.merge_moves_to_done' => (bool) setting('work.merge_moves_to_done', true),
            'work.digest_enabled' => (bool) setting('work.digest_enabled', true),
            'work.digest_time' => (string) setting('work.digest_time', '08:00'),
        ];
    }

    public function save(array $state): void
    {
        // An absent key means the section was never rendered — a company without the module — and
        // writing a value nobody chose is the bug this guard prevents.
        if (! array_key_exists('work.estimation_unit', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);

        $settings->set('work.estimation_unit', in_array($state['work.estimation_unit'], ['hours', 'points'], true) ? $state['work.estimation_unit'] : 'hours');
        $settings->set('work.merge_moves_to_done', (bool) ($state['work.merge_moves_to_done'] ?? true));
        $settings->set('work.digest_enabled', (bool) ($state['work.digest_enabled'] ?? true));
        $settings->set('work.digest_time', substr((string) ($state['work.digest_time'] ?? '08:00'), 0, 5) ?: '08:00');
    }
}
