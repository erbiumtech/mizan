<?php

namespace App\Filament\Pages;

use App\Services\FinancialReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class TrialBalance extends Page
{
    protected string $view = 'filament.pages.trial-balance';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Trial Balance';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public static function canAccess(): bool
    {
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
            ->trialBalance($this->data['as_of'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(
                    fn (): string => route('reports.trial-balance', array_filter([
                        'as_of' => $this->data['as_of'] ?? null,
                        'format' => 'pdf',
                    ])),
                    shouldOpenInNewTab: true,
                ),
        ];
    }
}
