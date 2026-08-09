<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Services\FinancialReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * What the company owns, owes and is worth, on a date.
 *
 * First in Reports rather than after the trial balance: the trial balance proves
 * the books add up, this one says what they say — and it is the first statement
 * anybody outside the company asks for.
 */
class BalanceSheet extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.balance-sheet';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $title = 'Balance Sheet';

    protected static ?int $navigationSort = 0;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['as_of' => now()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('as_of')
                    ->label('As of date')
                    ->native(false)
                    ->default(now())
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getReport(): array
    {
        return app(FinancialReportService::class)
            ->balanceSheet($this->data['as_of'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('balance-sheet', 'Balance Sheet: Help'),

            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(
                    // The company is a path segment now, not a filter: the page
                    // is outside the panel and resolves its tenant from the URL.
                    fn (): string => route('reports.balance-sheet', [
                        'company' => Filament::getTenant()?->slug,
                        ...array_filter([
                            'as_of' => $this->data['as_of'] ?? null,
                            'format' => 'pdf',
                        ]),
                    ]),
                    shouldOpenInNewTab: true,
                ),
        ];
    }
}
