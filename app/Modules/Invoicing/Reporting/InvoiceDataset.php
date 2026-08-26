<?php

namespace App\Modules\Invoicing\Reporting;

use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;

/**
 * Invoices — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **Sales, purchases and credit notes are one subject, because `kind` is a column and not a table.** A report
 * over "invoices" that silently meant sales would be wrong for anybody who asked about payables, and there is
 * no honest way to guess which they meant. So `kind` is a filter, defaulted to nothing, and a report that
 * cares says so — which also makes "sales and purchases side by side" a report somebody can build.
 *
 * **Outstanding is derived, and that is not a shortcut.** `total - amount_paid` is the figure every ageing
 * report in this application computes, and there is no column holding it: a stored balance would be a second
 * copy of the truth that a payment has to remember to update. Being derived, it cannot be summed in SQL — so
 * "total outstanding by customer" is `InvoiceService::outstandingReceivables()`, the coded report, exactly as
 * item 7 intends. What the builder gives is the list, with the figure on each row.
 *
 * **The date and the due date are both filterable and only one is the period.** An invoice's period is when
 * it was raised; its due date answers a different question ("what falls due next month") and a report can ask
 * that without the mandatory period following it around.
 */
class InvoiceDataset extends Dataset
{
    public static function label(): string
    {
        return 'Invoices';
    }

    public static function description(): string
    {
        return 'One invoice, credit note or purchase bill. Its lines are a subject of their own.';
    }

    public static function model(): string
    {
        return Invoice::class;
    }

    public static function permission(): string
    {
        return 'InvoiceView';
    }

    public static function periodColumn(): ?string
    {
        return 'invoice_date';
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('invoice_number', 'Invoice', groupable: false),
            DatasetColumn::make('invoice_date', 'Date', DatasetColumn::DATE),
            DatasetColumn::make('due_date', 'Due', DatasetColumn::DATE),
            DatasetColumn::make('kind', 'Kind'),
            DatasetColumn::make('status', 'Status'),
            DatasetColumn::make('currency_code', 'Currency'),

            DatasetColumn::related('contact', 'Contact', 'contact.name', groupBy: 'contact_id'),
            DatasetColumn::related('project', 'Project', 'project.name', groupBy: 'project_id'),

            DatasetColumn::make('subtotal', 'Subtotal', DatasetColumn::MONEY),
            DatasetColumn::make('tax_amount', 'Tax', DatasetColumn::MONEY),
            DatasetColumn::make('total', 'Total', DatasetColumn::MONEY),
            DatasetColumn::make('amount_paid', 'Paid', DatasetColumn::MONEY),

            DatasetColumn::derived(
                'outstanding',
                'Outstanding',
                fn (Invoice $invoice): float => round((float) $invoice->total - (float) $invoice->amount_paid, 2),
                DatasetColumn::MONEY,
            ),

            DatasetColumn::make('memo', 'Memo', groupable: false),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Invoice date', 'invoice_date'),
            DatasetFilter::dateRange('due', 'Due date', 'due_date'),
            DatasetFilter::select('kind', 'Kind', 'kind', fn (): array => [
                Invoice::KIND_SALE => 'Sale',
                Invoice::KIND_PURCHASE => 'Purchase',
                Invoice::KIND_CREDIT_NOTE => 'Credit note',
            ]),
            DatasetFilter::select('status', 'Status', 'status', fn (): array => [
                Invoice::STATUS_DRAFT => 'Draft',
                Invoice::STATUS_ISSUED => 'Issued',
                Invoice::STATUS_PARTIALLY_PAID => 'Partially paid',
                Invoice::STATUS_PAID => 'Paid',
                Invoice::STATUS_VOID => 'Void',
            ]),
            DatasetFilter::select(
                'contact',
                'Contact',
                'contact_id',
                fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all(),
            ),
            DatasetFilter::search('find', 'Number or memo contains', ['invoice_number', 'memo']),
        ];
    }
}
