<?php

namespace App\Filament\Pages;

use App\Services\FinancialReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class ProfitAndLoss extends Page
{
    protected string $view = 'filament.pages.profit-and-loss';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Profit & Loss';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ReportView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => null,
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live(),
                DatePicker::make('to')
                    ->label('To')
                    ->native(false)
                    ->afterOrEqual('from')
                    ->default(now())
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getReport(): array
    {
        return app(FinancialReportService::class)->profitAndLoss(
            $this->data['from'] ?? null,
            $this->data['to'] ?? null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(
                    fn (): string => route('reports.profit-and-loss', array_filter([
                        'from' => $this->data['from'] ?? null,
                        'to' => $this->data['to'] ?? null,
                        'format' => 'pdf',
                    ])),
                    shouldOpenInNewTab: true,
                ),
        ];
    }
}
