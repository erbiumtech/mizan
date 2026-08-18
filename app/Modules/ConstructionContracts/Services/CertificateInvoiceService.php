<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\Accounting\Support\ConstructionAccounts;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TenantTransaction;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where construction stops and `invoices` takes over — `docs/construction-management-plan.md` §10.4.
 *
 * **The boundary is sharp: construction owns everything up to and including the certificate, and this produces a
 * *draft* invoice and stops.** The precedent is verbatim in this repository — `QuotationService::convertToInvoice()`
 * "deliberately stops at draft… issuing stays the deliberate act it already is", and `quotation.invoice_id` is what
 * prevents a second conversion. Both are copied, down to the refusal messages.
 *
 * Three rules carry money here, and the first is the one an audit looks for.
 *
 *  - **Invoice gross, with retention as an asset — never net.** Retention is money earned and contractually owed,
 *    merely not yet payable. Invoicing net understates revenue and turnover by up to a tenth for the whole life of
 *    the job, and then makes the release invoice look like revenue recognised in a period when no work happened.
 *  - **One invoice line per deduction group, never one per contract item.** A four-hundred-item bill would
 *    otherwise produce a four-hundred-line invoice with uniform tax treatment, and the client's accounts department
 *    reconciles against the certificate anyway.
 *  - **The period movement is invoiced, not the cumulative figure.** Every figure on a certificate is to date; what
 *    is being billed is what has become due since the last one. The `previous_certificates` deduction row is the
 *    mechanism that turns one into the other, so it is *not* an invoice line — including it would deduct the same
 *    money twice.
 *
 * Invoicing is **guarded rather than required** (§18): without it this service refuses in one sentence and the
 * certificate register is still the whole deliverable, because a payment certificate is a contractual instrument
 * rather than a step towards a tax invoice.
 */
class CertificateInvoiceService
{
    /**
     * Which account each kind of deduction lands on.
     *
     * The map is the point of `invoice_lines.account_id`, which its own migration describes as the posting override
     * for non-product lines. Retention to an **asset**; advance recovery against the **liability** the advance
     * created; anything that is a reduction in the value of the work — an NCR deduction, damages — back against
     * contract revenue, because that is what it is.
     *
     * @var array<string, string>
     */
    private const ACCOUNT_FOR = [
        CertificateDeduction::KIND_RETENTION => 'retention_receivable',
        CertificateDeduction::KIND_RETENTION_RELEASE => 'retention_receivable',
        CertificateDeduction::KIND_ADVANCE_RECOVERY => 'contract_liabilities',
        CertificateDeduction::KIND_NCR => 'contract_revenue',
        CertificateDeduction::KIND_LIQUIDATED_DAMAGES => 'contract_revenue',
        CertificateDeduction::KIND_UNFIXED_MATERIALS => 'materials_on_site',
        CertificateDeduction::KIND_BACK_CHARGE => 'contract_revenue',
        CertificateDeduction::KIND_CONTRA_CHARGE => 'contract_revenue',
        CertificateDeduction::KIND_OTHER => 'contract_revenue',
    ];

    /**
     * Deduction kinds that are **not** invoice lines.
     *
     * `previous_certificates` is how a cumulative certificate expresses a period figure; billing it as a deduction
     * on top of already billing the period movement would deduct the same money twice. `tax_withheld` is the
     * client's own withholding against the invoice rather than a reduction of the work, and it belongs to whoever
     * records the receipt.
     *
     * @var array<int, string>
     */
    private const NOT_INVOICED = [
        CertificateDeduction::KIND_PREVIOUS_CERTIFICATES,
        CertificateDeduction::KIND_TAX_WITHHELD,
    ];

    public function canRaise(): bool
    {
        return modules()->enabled('invoicing');
    }

    /**
     * Raise the draft invoice for a certificate.
     *
     * A *sale* invoice on the receivable side and a *purchase* invoice on the payable one — `invoices.kind` is one
     * table for both, with `InvoiceService` branching only at the posting step, which is the same decision §8.1
     * makes for contracts.
     */
    public function raise(PaymentCertificate $certificate): Invoice
    {
        if (! $this->canRaise()) {
            throw new RuntimeException(
                'A certificate cannot become an invoice without the Invoicing module. The certificate itself '
                .'stands: it is a contractual instrument, and its retention ledger and printed forms do not need '
                .'a tax invoice to exist.'
            );
        }

        if (! $certificate->isIssued()) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} is {$certificate->status}. Only an issued certificate is "
                .'billable — a draft is still being argued about.'
            );
        }

        if ($certificate->invoice_id) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} has already been invoiced. Raising another would bill the "
                .'same money twice.'
            );
        }

        $contract = $certificate->contract;

        if (! $contract->contact_id) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} names no other party, so there is nobody for the invoice to be "
                .'addressed to. Set the employer on the contract first.'
            );
        }

        $lines = $this->lines($certificate);
        $total = round(array_sum(array_column($lines, 'line_total')), 2);

        return TenantTransaction::run(function () use ($certificate, $contract, $lines, $total): Invoice {
            $invoice = Invoice::create([
                'kind' => $contract->side === Contract::SIDE_RECEIVABLE ? Invoice::KIND_SALE : Invoice::KIND_PURCHASE,
                // DRAFT, always. Issuing transmits, and transmission is what cannot be undone.
                'status' => Invoice::STATUS_DRAFT,
                'contact_id' => $contract->contact_id,
                'currency_code' => $contract->currency_code,
                'exchange_rate' => $contract->exchange_rate,
                'invoice_date' => ($certificate->issued_on ?? now())->toDateString(),
                'due_date' => $certificate->due_on?->toDateString(),
                'subtotal' => $total,
                // Tax sits on the gross line only, which is the right treatment for retention as a timing
                // difference in most regimes — and §10.4 flags it as a jurisdiction question to confirm rather
                // than a settled one, so nothing is assumed here.
                'tax_amount' => 0,
                'total' => $total,
                'memo' => "Per {$certificate->certificate_number}, "
                    .$contract->vocabulary()->certifierDocument()
                    .' valued to '.$certificate->period_end?->format('d M Y'),
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            $certificate->update(['invoice_id' => $invoice->getKey()]);

            activity('Construction')
                ->performedOn($certificate)
                ->causedBy(auth()->user())
                ->event('invoiced')
                ->withProperties(['invoice_id' => $invoice->getKey()])
                ->log("{$certificate->certificate_number} became draft invoice {$invoice->invoice_number}");

            return $invoice->refresh();
        });
    }

    /**
     * §10.4's table: the work executed this period, then one line per deduction group.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(PaymentCertificate $certificate): array
    {
        $contract = $certificate->contract;
        $period = $certificate->period_end?->format('d M Y');

        // Gross, and the whole of the period's movement. Retention comes off as its own line below rather than
        // being netted off here — see the class docblock on why that distinction is the point of this service.
        $lines = [[
            'description' => "Work executed to {$period} per {$certificate->certificate_number}",
            'quantity' => 1,
            'unit_price' => $certificate->grossThisPeriod(),
            'line_total' => $certificate->grossThisPeriod(),
            'account_id' => ConstructionAccounts::id('contract_revenue'),
        ]];

        foreach ($certificate->deductions as $deduction) {
            if (in_array($deduction->kind, self::NOT_INVOICED, true)) {
                continue;
            }

            $amount = round((float) $deduction->amount, 2);

            if ($amount === 0.0) {
                continue;
            }

            $lines[] = [
                // The certificate's own wording, so the client's accounts department can match the invoice line to
                // the certificate line without translating between two descriptions of the same deduction.
                'description' => $deduction->description,
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
                'account_id' => ConstructionAccounts::id(
                    self::ACCOUNT_FOR[$deduction->kind] ?? 'contract_revenue',
                ),
            ];
        }

        return $lines;
    }
}
