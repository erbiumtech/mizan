<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Modules\Invoicing\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * An invoice, read — the Material "Record detail (Invoice)" design.
 *
 * The number with a status chip beside it, the three things somebody opens an invoice to do as buttons
 * (download it, send it, take a payment), and everything else in the overflow. The actions are the list's own
 * (`InvoicesTable::invoiceActions()`), so their permissions, confirmations and services are the ones the list
 * already has; nothing here decides anything about an invoice. Lines and history are part of the page's
 * tabs, so the edit page's relation managers are not shown here as well.
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /** What every tab reads, loaded once with the record so no list loads its parent row by row. */
    public const RELATIONS = ['contact', 'project', 'lines', 'events.causer'];

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(self::RELATIONS);
    }

    public function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    /** The tabs carry the lines and the history; the relation managers stay on the edit page. */
    public function getRelationManagers(): array
    {
        return [];
    }

    public function getTitle(): string|Htmlable
    {
        return (string) $this->getRecord()->invoice_number;
    }

    public function getHeading(): string|Htmlable
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();
        $chip = InvoiceInfolist::statusChip($invoice);

        return new HtmlString(
            '<span class="md-detail-heading">'.e($invoice->invoice_number)
            .' <span class="md-status-chip md-status-chip-'.$chip['tone'].'">'.e($chip['label']).'</span></span>'
        );
    }

    protected function getHeaderActions(): array
    {
        $actions = collect(InvoicesTable::invoiceActions())
            ->keyBy(fn (Action $action): string => (string) $action->getName())
            ->map(fn (Action $action): Action => $action->after(fn () => $this->refreshInvoice()));

        $take = fn (string $name): ?Action => $actions->pull($name);

        return array_values(array_filter([
            $take('pdf')?->label(__('Download PDF'))->color('primary')->outlined(),
            $take('email')?->color('gray'),
            $take('issue')?->color('primary'),
            $take('recordPayment')?->color('primary'),

            ActionGroup::make(array_values(array_filter([
                EditAction::make(),
                ...$actions->values()->all(),
                DeleteAction::make(),
            ])))
                ->label(__('More actions'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->iconButton()
                ->color('gray'),
        ]));
    }

    /** After an action, read the invoice again: its status, balance and history may all have changed. */
    protected function refreshInvoice(): void
    {
        $this->getRecord()->refresh()->load(self::RELATIONS);
    }
}
