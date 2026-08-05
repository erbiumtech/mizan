<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * What is owed, by how late it is.
 *
 * The buckets have been computed by InvoiceService::outstandingReceivables() and
 * outstandingPayables() since they were written, and until now the only caller in
 * the repository was a test — no page, route, widget or endpoint reached either.
 *
 * Receivables and payables differ by one argument, so they share an
 * implementation and differ by a subclass, which is what gives each its own place
 * in the sidebar without a second copy of any of this.
 */
abstract class AgedInvoices extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.aged-invoices';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    /** Invoice::KIND_SALE for receivables, KIND_PURCHASE for payables. */
    abstract public function kind(): string;

    /** The route name of the printable version. */
    abstract public function reportRoute(): string;

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
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getReport(): array
    {
        $invoices = app(InvoiceService::class);
        $asOf = $this->data['as_of'] ?? null;

        return $this->kind() === Invoice::KIND_SALE
            ? $invoices->outstandingReceivables($asOf)
            : $invoices->outstandingPayables($asOf);
    }

    /** Oldest first: the row that needs chasing is the one at the top. */
    public function rows(): array
    {
        $rows = $this->getReport()['invoices'];

        usort($rows, fn (array $a, array $b): int => $b['days_overdue'] <=> $a['days_overdue']);

        return $rows;
    }

    public function isReceivable(): bool
    {
        return $this->kind() === Invoice::KIND_SALE;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(
                    fn (): string => route($this->reportRoute(), [
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
