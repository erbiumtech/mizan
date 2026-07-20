<?php

namespace App\Nova\Metrics;

use App\Models\Invoice;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class UnpaidInvoices extends Value
{
    public $name = 'Unpaid Customer Invoices';

    public function calculate(NovaRequest $request): ValueResult
    {
        $open = Invoice::where('kind', Invoice::KIND_SALE)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->get();

        return $this->result(round($open->sum(fn (Invoice $i) => $i->outstanding()), 2))
            ->currency('PKR')
            ->allowZeroResult()
            ->suffix($open->count().' open');
    }

    public function ranges(): array
    {
        return [];
    }

    public function uriKey(): string
    {
        return 'unpaid-invoices';
    }
}
