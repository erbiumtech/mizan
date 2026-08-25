<?php

namespace App\Modules\Invoicing\Reporting;

use App\Modules\Invoicing\Models\InvoiceLine;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;

/**
 * Invoice lines — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **A separate subject from invoices, because the questions are different and the row counts differ by an
 * order of magnitude.** "What did we sell" is a line question — by product, by revenue account — and asking it
 * of the invoice subject would mean a join. "What do customers owe" is an invoice question and asking it here
 * would double-count every invoice with two lines. Two subjects, each of which answers its own question with
 * no join at all, is the whole shape item 1 is after.
 *
 * **The parent's date and number are related columns, and that is not "two subjects joined".** Item 7's
 * refusal is about joining two *subjects* — invoices to payslips, tickets to timesheets. A row's own parent is
 * its context, not a second subject: a line without its invoice's date cannot be put in a period at all, so
 * the relation is what makes the subject reportable rather than a shortcut around the rule.
 *
 * **Which means the period costs a subquery here.** `invoice.invoice_date` is a `whereHas`, the same trade
 * journal lines make. A denormalised date on the line would be faster and would be a second copy of a fact
 * with nothing keeping the copies equal.
 *
 * **Revenue account is the column that earns this subject its place.** Which account a line posts to is on
 * the line, not the invoice — one invoice can hit three revenue accounts — so "sales by revenue account" is
 * only answerable here, and it is the question the profit and loss raises and does not itself break down.
 */
class InvoiceLineDataset extends Dataset
{
    public static function label(): string
    {
        return 'Invoice lines';
    }

    public static function description(): string
    {
        return 'One line of an invoice: what was sold, at what price, to which account.';
    }

    public static function model(): string
    {
        return InvoiceLine::class;
    }

    /**
     * The invoice's permission, not a line's.
     *
     * There is no `InvoiceLineView` and there should not be: a line is part of an invoice, and anybody who may
     * read the invoice may read what is on it. Inventing a permission here would create a state — may see
     * invoices, may not see their lines — that no screen in the application produces.
     */
    public static function permission(): string
    {
        return 'InvoiceView';
    }

    public static function periodColumn(): ?string
    {
        return 'invoice.invoice_date';
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::related('invoice_date', 'Date', 'invoice.invoice_date', DatasetColumn::DATE),
            DatasetColumn::related('invoice_number', 'Invoice', 'invoice.invoice_number'),
            DatasetColumn::related('invoice_kind', 'Kind', 'invoice.kind'),
            DatasetColumn::related('invoice_status', 'Status', 'invoice.status'),
            DatasetColumn::related('contact', 'Contact', 'invoice.contact.name'),

            DatasetColumn::related('product', 'Product', 'product.name', groupBy: 'product_id'),
            DatasetColumn::related('product_sku', 'SKU', 'product.sku', groupBy: 'product_id'),
            DatasetColumn::related('account', 'Account', 'account.name', groupBy: 'account_id'),

            DatasetColumn::make('description', 'Description', groupable: false),
            DatasetColumn::make('quantity', 'Quantity', DatasetColumn::NUMBER),
            DatasetColumn::make('unit_price', 'Unit price', DatasetColumn::MONEY),
            DatasetColumn::make('tax_amount', 'Tax', DatasetColumn::MONEY),
            DatasetColumn::make('line_total', 'Total', DatasetColumn::MONEY),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Invoice date', 'invoice.invoice_date'),
            DatasetFilter::search('description', 'Description contains', ['description']),
        ];
    }
}
