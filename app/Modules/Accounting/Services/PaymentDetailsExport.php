<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Accounting\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exports Payment records into the FBR "Payment Details" XLSX layout
 * (Payment Id, CPR No, Withholding Agent Reg No, Wa Name, Section, Tax Month,
 * Tax Year, Taxable Amount, Paid Amount, Due Date, Payment Date, Payment
 * Source, Claimed) followed by a Total Paid Amount summary row.
 *
 * The app does not store the FBR-specific challan fields (CPR No, WA Reg No,
 * section codes); those columns are mapped from the closest available data and
 * left blank where nothing corresponds.
 */
class PaymentDetailsExport
{
    /** @var list<string> */
    protected array $headings = [
        'Payment Id',
        'CPR No',
        'Withholding Agent Reg No',
        'Wa Name',
        'Section',
        'Tax Month',
        'Tax Year',
        'Taxable Amount',
        'Paid Amount',
        'Due Date',
        'Payment Date',
        'Payment Source',
        'Claimed',
    ];

    /**
     * Write the export to an absolute file path.
     */
    public function writeToFile(Builder $query, string $path): void
    {
        $query = (clone $query)->with([
            'transactionType',
            'companyBankAccount',
            'payable' => fn ($morphTo) => $morphTo->morphWith([Employee::class => ['user']]),
        ]);

        $writer = new Writer;
        $writer->openToFile($path);

        $headerStyle = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues($this->headings, $headerStyle));

        $totalPaid = 0.0;

        $query->orderBy('value_date')->orderBy('id')->chunk(500, function ($payments) use ($writer, &$totalPaid): void {
            foreach ($payments as $payment) {
                $totalPaid += (float) $payment->amount;
                $writer->addRow(Row::fromValues($this->rowFor($payment)));
            }
        });

        // Total Paid Amount summary row (label in the "Paid Amount" column header position).
        $summary = array_fill(0, count($this->headings), '');
        $summary[7] = 'Total Paid Amount';
        $summary[8] = number_format($totalPaid, 2, '.', '');
        $writer->addRow(Row::fromValues($summary, (new Style)->setFontBold()));

        $writer->close();
    }

    /**
     * Map a Payment onto the FBR Payment Details columns.
     *
     * @return list<string>
     */
    protected function rowFor(Payment $payment): array
    {
        $valueDate = $payment->value_date;
        $payable = $payment->payable;

        $waName = $payment->companyBankAccount?->title
            ?? ($payable instanceof Employee ? ($payable->user?->name ?? $payable->employee_id) : $payable?->name)
            ?? '';

        return [
            (string) $payment->id,
            (string) ($payment->reference ?? ''),   // CPR No — nearest match is the payment reference
            '',                                      // Withholding Agent Reg No — not stored
            (string) $waName,
            (string) ($payment->transactionType?->name ?? ''), // Section (u/s)
            $valueDate?->format('M') ?? '',          // Tax Month
            $valueDate?->format('Y') ?? '',          // Tax Year
            '',                                      // Taxable Amount — not stored per payment
            number_format((float) $payment->amount, 2, '.', ''), // Paid Amount
            $valueDate?->format('d-M-Y') ?? '',      // Due Date
            $valueDate?->format('d-M-Y') ?? '',      // Payment Date
            $payment->resolvedPaymentType(),         // Payment Source
            ucfirst((string) $payment->status),      // Claimed / status
        ];
    }
}
