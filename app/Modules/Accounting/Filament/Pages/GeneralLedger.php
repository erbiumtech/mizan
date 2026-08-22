<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Modules\Accounting\Support\ReportPeriod;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Every account, its entries in date order, opening → movement → closing.
 *
 * The report an auditor asks for first, and the one this application could not produce — not for want of
 * the computation, which `GeneralLedgerService::generalLedger()` has carried all along, but for want of
 * anything that called it. It had no page, no widget and no command: the only ledger a person could open
 * was one account's, through the register.
 *
 * The date pair defaults to the financial year to date rather than to the calendar year. That is not a
 * preference — this application's years run 1 July to 30 June, and a ledger opened on 1 January would
 * silently drop the first half of the year. See ReportPeriod.
 */
class GeneralLedger extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.general-ledger';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $title = 'General Ledger';

    protected static ?int $navigationSort = 2;

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
        $period = ReportPeriod::toDate(now()->toDateString());

        $this->form->fill(['from' => $period['from'], 'to' => $period['to']]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('from')->label('From')->native(false)->live(),
                DatePicker::make('to')->label('To')->native(false)->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLedgers(): array
    {
        return app(GeneralLedgerService::class)->generalLedger(
            $this->data['from'] ?? null,
            $this->data['to'] ?? null,
        );
    }

    /**
     * The period's debits and credits, which must agree.
     *
     * Summed from the lines being shown rather than asked of the ledger separately: a total that agrees
     * with a figure nobody can see on the page proves nothing.
     *
     * @return array{debits: float, credits: float, balanced: bool}
     */
    public function getTotals(): array
    {
        $debits = 0.0;
        $credits = 0.0;

        foreach ($this->getLedgers() as $ledger) {
            $debits += array_sum(array_column($ledger['lines'], 'debit'));
            $credits += array_sum(array_column($ledger['lines'], 'credit'));
        }

        return [
            'debits' => round($debits, 2),
            'credits' => round($credits, 2),
            'balanced' => round($debits, 2) === round($credits, 2),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('general-ledger', 'General Ledger: Help'),
        ];
    }
}
