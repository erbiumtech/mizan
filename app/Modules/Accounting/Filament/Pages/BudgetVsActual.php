<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Services\BudgetReportService;
use App\Modules\Core\Models\FiscalYear;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class BudgetVsActual extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.budget-vs-actual';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Budget vs Actual';

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // BudgetView rather than ReportView: this shows what the company
        // intended to spend, which is a plan somebody may be trusted to read the
        // accounts without being shown.
        return auth()->user()?->can('BudgetView') ?? false;
    }

    public function mount(): void
    {
        $budget = $this->defaultBudget();

        $this->form->fill([
            'budget_id' => $budget?->getKey(),
            'from' => $budget?->fiscalYear?->start_date?->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('budget_id')
                    ->label('Budget')
                    ->options(fn (): array => Budget::query()
                        ->with('fiscalYear')
                        ->where('is_active', true)
                        ->get()
                        ->mapWithKeys(fn (Budget $budget): array => [
                            $budget->getKey() => $budget->name.' ('.($budget->fiscalYear?->name ?? 'no year').')',
                        ])
                        ->all())
                    ->live()
                    // Changing budget moves the window to that budget's year.
                    // Leaving last year's dates against this year's plan reports
                    // a full year of plan against no actuals at all.
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $year = Budget::find($state)?->fiscalYear;

                        $set('from', $year?->start_date?->toDateString());
                        $set('to', min(
                            now()->toDateString(),
                            $year?->end_date?->toDateString() ?? now()->toDateString(),
                        ));
                    }),

                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live(),

                DatePicker::make('to')
                    ->label('To')
                    ->native(false)
                    ->afterOrEqual('from')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    /**
     * The budget for the open year if there is one, else the newest active plan.
     */
    private function defaultBudget(): ?Budget
    {
        $active = Budget::query()->with('fiscalYear')->where('is_active', true);

        if (($current = FiscalYear::current()) !== null) {
            $thisYear = (clone $active)
                ->where('fiscal_year_id', $current->getKey())
                ->orderByDesc('id')
                ->first();

            if ($thisYear !== null) {
                return $thisYear;
            }
        }

        return $active->orderByDesc('id')->first();
    }

    public function getReport(): ?array
    {
        $budget = Budget::with('fiscalYear')->find($this->data['budget_id'] ?? null);

        if ($budget === null) {
            return null;
        }

        return app(BudgetReportService::class)->report(
            $budget,
            $this->data['from'] ?? null,
            $this->data['to'] ?? null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('budget-vs-actual', 'Budget vs Actual: Help'),
        ];
    }
}
