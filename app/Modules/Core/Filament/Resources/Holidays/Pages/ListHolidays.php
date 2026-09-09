<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\Holidays\HolidayResource;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Services\PakistanPublicHolidays;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListHolidays extends ListRecords
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('holidays', 'Holidays: Help'),
            $this->addPakistanHolidaysAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Fill a year with the federal public holidays, skipping any date already listed.
     *
     * An action here rather than a seeder, because a holiday is a company's decision: a company outside
     * Pakistan, or one that closes on different days, must not find fourteen rows it never asked for. The
     * administrator picks the year and the list is added once; what they had already entered is kept.
     */
    private function addPakistanHolidaysAction(): Action
    {
        $years = PakistanPublicHolidays::years();

        return Action::make('addPakistanHolidays')
            ->label('Add Pakistan public holidays')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('create', Holiday::class) ?? false)
            ->modalHeading('Add Pakistan public holidays')
            ->modalDescription('The federal public holidays for the year. National days are added as recurring. '
                .'Eid, Ashura and Eid Milad-un-Nabi follow the moon: for a year already gazetted they are the notified '
                .'dates, for a later year they are estimates marked tentative in the notes — confirm them when the '
                .'Cabinet Division notifies. A date already in the list is left as it is.')
            ->modalSubmitActionLabel('Add them')
            ->schema([
                Select::make('year')
                    ->label('Year')
                    ->options(array_combine($years, $years))
                    ->default(in_array((int) now()->year, $years, true) ? (int) now()->year : ($years[0] ?? null))
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $result = PakistanPublicHolidays::addMissing((int) $data['year']);

                Notification::make()->success()
                    ->title("Added {$result['added']} holiday(s) for {$data['year']}.")
                    ->body($result['skipped'] > 0
                        ? "{$result['skipped']} date(s) were already listed and were left unchanged."
                        : 'Check the tentative dates against the Cabinet Division notification when it comes.')
                    ->send();
            });
    }
}
