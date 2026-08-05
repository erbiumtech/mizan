<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Services\CurrencyRevaluationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Restating foreign balances at the rate on a date, and posting the difference.
 *
 * A page rather than a command because somebody has to look at it first: the adjustment
 * depends entirely on which rate was recorded for the date, and posting a month-end gain
 * against a rate nobody checked is worse than not posting one.
 */
class CurrencyRevaluation extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.currency-revaluation';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $title = 'Currency Revaluation';

    protected static ?int $navigationSort = 8;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // It posts a journal entry, so it takes the permission for posting one rather
        // than the permission to read a report.
        return auth()->user()?->can('JournalEntryCreate') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['as_of' => now()->endOfMonth()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('as_of')
                    ->label('As at')
                    ->required()
                    ->live()
                    ->helperText('Month end, normally. The rate in force on this date is the one used.'),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getReport(): array
    {
        return app(CurrencyRevaluationService::class)->preview($this->data['as_of'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revalue')
                ->label('Post revaluation')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalDescription('This posts an adjusting entry dated as at the date above. Running it again '
                    .'changes nothing unless a rate or a transaction has moved.')
                ->disabled(fn (): bool => ! $this->getReport()['has_adjustment'])
                ->action(function (): void {
                    try {
                        $entry = app(CurrencyRevaluationService::class)->revalue($this->data['as_of'] ?? null);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $entry
                        ? Notification::make()->success()
                            ->title('Posted as '.$entry->entry_number)
                            ->body('Foreign balances now read at the rate on '.$this->data['as_of'].'.')
                            ->send()
                        : Notification::make()->info()
                            ->title('Nothing to post')
                            ->body('Every foreign balance is already translated at that date.')
                            ->send();
                }),
        ];
    }
}
