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
 * Where the cash went, indirect method.
 *
 * A CashFlowChart widget shipped and no statement did, so the shape of the month
 * was visible and its explanation was not: profit and cash are different things,
 * and the difference is what this page is.
 */
class CashFlow extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.cash-flow';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $title = 'Cash Flow';

    protected static ?int $navigationSort = 3;

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
        // A cash flow needs a start: without one the opening balance is the
        // beginning of the book, which is a true but useless statement.
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('from')->label('From')->native(false)->live(),
                DatePicker::make('to')->label('To')->native(false)->afterOrEqual('from')->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getReport(): array
    {
        return app(FinancialReportService::class)->cashFlow(
            $this->data['from'] ?? null,
            $this->data['to'] ?? null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('cash-flow', 'Cash Flow: Help'),

            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(
                    fn (): string => route('reports.cash-flow', [
                        'company' => Filament::getTenant()?->slug,
                        ...array_filter([
                            'from' => $this->data['from'] ?? null,
                            'to' => $this->data['to'] ?? null,
                            'format' => 'pdf',
                        ]),
                    ]),
                    shouldOpenInNewTab: true,
                ),
        ];
    }
}
