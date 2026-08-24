<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use App\Support\TenantDb;
use Illuminate\Support\Collection;

/**
 * Revenue by customer, project and product — `docs/reports-expansion-plan.md` Phase 3.4.
 *
 * "One report with a dimension filter, gross and net of credit notes. `invoices.project_id` exists and
 * nothing reports on it."
 *
 * **Three groupings in one table rather than a filter**, which is a departure from the plan's wording and
 * deliberate. A dimension picker would have to be declared in `ReportPane::ASKS` — an Accounting constant —
 * and putting an Invoicing concept there is exactly the coupling Phase 1.2 removed from `supports()`. The
 * codebase already has the answer: *Win/Loss* stacks three groupings in one table with a labelled first
 * column, and reading them together is better than switching between them anyway, because a customer whose
 * revenue is all on one project is a different risk from one spread across four.
 *
 * **Net of credit notes, and a credit note is attributed to the invoice it credits.** Credit notes are
 * stored with positive amounts and posted as the reverse, so subtracting them is the whole of "net". Where
 * one names the invoice it corrects, its dimension follows *that* invoice: the revenue was recognised
 * against that customer, project and product, so the correction belongs in the same place. Attributing a
 * credit note by its own columns would move the reversal to whatever was typed on it.
 *
 * **Issued invoices only.** A draft is not revenue and a void one never was.
 */
class RevenueReports
{
    use ReportShapes;

    /**
     * Keeps a descending sort key positive.
     *
     * Ten trillion, comfortably past any invoice total this application will hold, and comfortably inside
     * the fifteen digits the key is padded to.
     */
    private const SORT_OFFSET = 10_000_000_000_000;

    /** @var array<int, string> */
    private const COUNTED = [
        Invoice::STATUS_ISSUED,
        Invoice::STATUS_PARTIALLY_PAID,
        Invoice::STATUS_PAID,
    ];

    public function byDimension(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $invoices = Invoice::query()
            ->whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE])
            ->whereIn('status', self::COUNTED)
            ->whereDate('invoice_date', '>=', $period['from'])
            ->whereDate('invoice_date', '<=', $period['to'])
            ->get();

        if ($invoices->isEmpty()) {
            return $this->emptyRevenue($period);
        }

        $sales = $invoices->where('kind', Invoice::KIND_SALE)->keyBy('id');
        $credits = $invoices->where('kind', Invoice::KIND_CREDIT_NOTE);

        $customers = $this->customerNames($invoices);
        $projects = $this->projectNames($invoices);

        $buckets = [];

        // Customer and project come off the invoice; product needs its lines, which is one more query.
        foreach ($sales as $invoice) {
            $this->add($buckets, 'By customer', $this->customerFor($invoice, $customers), (float) $invoice->total, 0.0);
            $this->add($buckets, 'By project', $this->projectFor($invoice, $projects), (float) $invoice->total, 0.0);
        }

        foreach ($credits as $credit) {
            // The invoice being corrected, where it is named — so the reversal lands where the revenue did.
            $against = $credit->credits_invoice_id === null ? null : $sales->get($credit->credits_invoice_id);
            $source = $against ?? $credit;

            $this->add($buckets, 'By customer', $this->customerFor($source, $customers), 0.0, (float) $credit->total);
            $this->add($buckets, 'By project', $this->projectFor($source, $projects), 0.0, (float) $credit->total);
        }

        $this->addProducts($buckets, $sales, $credits);

        $rows = [];
        $gross = 0.0;
        $credited = 0.0;

        // Groupings in reading order, and biggest first inside each. Insertion order would put them in
        // whatever sequence the invoices happened to arrive in, which is no order at all.
        //
        // One computed key rather than `sortBy([...])`'s multi-comparator form, for the same reason the
        // payroll register's component sort uses one: that form is easy to get subtly wrong, and a sort that
        // is quietly not sorting looks exactly like data that arrived in that order. The offset keeps the
        // net positive so the padding sorts as a number — a credit note larger than its invoice is a real
        // state and would otherwise sort as a minus sign.
        $buckets = collect($buckets)
            ->sortBy(fn (array $figures, string $label): string => sprintf(
                '%d-%015d',
                match (true) {
                    str_starts_with($label, 'By customer') => 0,
                    str_starts_with($label, 'By project') => 1,
                    default => 2,
                },
                self::SORT_OFFSET - (int) round($figures['gross'] - $figures['credited']),
            ))
            ->all();

        foreach ($buckets as $label => $figures) {
            $rows[] = [
                $label,
                number_format($figures['invoices']),
                number_format($figures['gross'], 0),
                $figures['credited'] > 0 ? number_format($figures['credited'], 0) : '—',
                number_format($figures['gross'] - $figures['credited'], 0),
            ];

            // Only the customer grouping is totalled, because the three groupings are the same money three
            // times over — the same trap the SLA report's two groupings carry, and the same answer.
            if (str_starts_with($label, 'By customer')) {
                $gross += $figures['gross'];
                $credited += $figures['credited'];
            }
        }

        return $this->table(
            'RevenueByDimension',
            'Revenue by Customer, Project and Product',
            $this->subtitle('invoiced between '.$period['from'].' and '.$period['to']),
            ['Bucket', 'Invoices', 'Gross', 'Credited', 'Net'],
            'minmax(0, 1fr) 8rem 11rem 11rem 11rem',
            [1, 2, 3, 4],
            $rows,
            [
                ['label' => 'NET REVENUE', 'value' => round($gross - $credited, 2), 'accent' => true],
                ['label' => 'CREDITED', 'value' => round($credited, 2), 'accent' => false],
            ],
            $this->revenueNote($buckets, round($gross, 2), round($credited, 2)),
            [
                'Total — by customer',
                number_format($sales->count()),
                number_format($gross, 0),
                $credited > 0 ? number_format($credited, 0) : '—',
                number_format($gross - $credited, 0),
            ],
            'Nothing was invoiced in this period.',
        );
    }

    /**
     * Add to a bucket, creating it on first sight.
     *
     * @param  array<string, array{invoices: int, gross: float, credited: float}>  $buckets
     */
    private function add(array &$buckets, string $heading, string $label, float $gross, float $credited): void
    {
        $key = $heading.' · '.$label;

        $buckets[$key] ??= ['invoices' => 0, 'gross' => 0.0, 'credited' => 0.0];
        $buckets[$key]['invoices'] += $gross > 0 ? 1 : 0;
        $buckets[$key]['gross'] += $gross;
        $buckets[$key]['credited'] += $credited;
    }

    /**
     * Revenue per product, from the invoice lines.
     *
     * A line with no product is real and common — a service, a one-off, anything typed straight onto an
     * invoice — and it is named rather than dropped, because the product grouping would otherwise not add up
     * to the customer grouping and nothing on the report would say why.
     *
     * @param  array<string, array<string, mixed>>  $buckets
     * @param  Collection<int, Invoice>  $sales
     * @param  Collection<int, Invoice>  $credits
     */
    private function addProducts(array &$buckets, Collection $sales, Collection $credits): void
    {
        $lines = InvoiceLine::query()
            // `array_merge`, not `+`: the plus operator unions arrays by key, and both of these are
            // nought-indexed, so it would have silently kept only as many credit ids as there were sales.
            ->whereIn('invoice_id', array_merge($sales->modelKeys(), $credits->modelKeys()))
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $products = $this->productNames($lines);
        // A set rather than a `contains()` per line, which would be a scan of the credit notes for every
        // line on the report.
        $creditIds = array_flip($credits->modelKeys());

        foreach ($lines as $line) {
            $label = $line->product_id === null
                ? 'Not a product'
                : ($products[$line->product_id] ?? 'Product #'.$line->product_id);

            $isCredit = isset($creditIds[$line->invoice_id]);

            $this->add(
                $buckets,
                'By product',
                $label,
                $isCredit ? 0.0 : (float) $line->line_total,
                $isCredit ? (float) $line->line_total : 0.0,
            );
        }
    }

    /**
     * What the three groupings say, and the one thing a reader must not do with them.
     *
     * Add them together. They are the same money viewed three ways, so the record row totals the customer
     * grouping alone — the same arithmetic trap the SLA report carries with its two groupings, and the same
     * answer to it.
     *
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function revenueNote(array $buckets, float $gross, float $credited): string
    {
        $unattributed = $buckets['By project · No project'] ?? null;

        return mb_strtoupper(implode(' · ', array_filter([
            'three groupings of the same money, totalled once',
            $credited > 0
                ? number_format($credited / max($gross, 0.01) * 100, 1).'% credited back'
                : 'nothing credited back',
            $unattributed !== null && $unattributed['gross'] > 0
                ? number_format($unattributed['gross'], 0).' invoiced against no project'
                : null,
        ])));
    }

    /** @param  array<int, string>  $customers */
    private function customerFor(Invoice $invoice, array $customers): string
    {
        return $invoice->contact_id === null
            ? 'No customer'
            : ($customers[$invoice->contact_id] ?? 'Customer #'.$invoice->contact_id);
    }

    /** @param  array<int, string>  $projects */
    private function projectFor(Invoice $invoice, array $projects): string
    {
        return $invoice->project_id === null
            ? 'No project'
            : ($projects[$invoice->project_id] ?? 'Project #'.$invoice->project_id);
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, string>
     */
    private function customerNames(Collection $invoices): array
    {
        $ids = $invoices->pluck('contact_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : TenantDb::table('contacts')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Project names, read through the query builder.
     *
     * `invoicing -> projects` is a declared coupling, so a model import would be legal — but the report needs
     * one column of one table and a company without the projects module still has `project_id` values from
     * before it was switched off. Reading the table answers for both without a guard.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, string>
     */
    private function projectNames(Collection $invoices): array
    {
        $ids = $invoices->pluck('project_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : TenantDb::table('projects')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<int, string>
     */
    private function productNames(Collection $lines): array
    {
        $ids = $lines->pluck('product_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : TenantDb::table('products')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptyRevenue(array $period): array
    {
        return $this->table(
            'RevenueByDimension',
            'Revenue by Customer, Project and Product',
            $this->subtitle('invoiced between '.$period['from'].' and '.$period['to']),
            ['Bucket', 'Invoices', 'Gross', 'Credited', 'Net'],
            'minmax(0, 1fr) 8rem 11rem 11rem 11rem',
            [1, 2, 3, 4],
            [],
            [
                ['label' => 'NET REVENUE', 'value' => 0.0, 'accent' => true],
                ['label' => 'CREDITED', 'value' => 0.0, 'accent' => false],
            ],
            'NOTHING WAS INVOICED IN THIS PERIOD',
            null,
            'Nothing was invoiced in this period.',
        );
    }
}
