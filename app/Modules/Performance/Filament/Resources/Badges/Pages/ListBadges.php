<?php

namespace App\Modules\Performance\Filament\Resources\Badges\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\Badges\BadgeResource;
use App\Modules\Performance\Services\Badges;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBadges extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = BadgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('badges', 'Badges: Help'),

            /*
             * The nightly run, now — docs/saas-launch-plan.md §14.9.
             *
             * `badges:award` runs on the timetable and a company that has just written a
             * rule should not have to wait until tomorrow to see whether it does anything.
             * Calls the service the command calls rather than shelling out to Artisan,
             * which is the convention here: the command is one caller of the service, not
             * the other way round.
             *
             * Awarding is idempotent — a badge somebody already holds is not awarded twice
             * — so pressing it twice is safe, which is what makes it a button at all.
             */
            Action::make('awardNow')
                ->label(__('Award badges now'))
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Run the badge rules now')
                ->modalDescription('Judges every rule against every employee as of today and awards what it '
                    .'finds. The nightly run does the same thing; this is for after writing a rule. Nobody is '
                    .'awarded a badge they already hold.')
                ->modalSubmitActionLabel('Run it')
                ->visible(fn (): bool => auth()->user()?->can('BadgeAward') ?? false)
                ->action(function (): void {
                    $result = app(Badges::class)->awardByRule();

                    Notification::make()->success()
                        ->title($result['awarded'] === 0
                            ? 'Nothing new to award.'
                            : 'Awarded '.$result['awarded'].' badge(s) across '.$result['badges'].' rule(s).')
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
