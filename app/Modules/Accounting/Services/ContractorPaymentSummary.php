<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Core\Models\FiscalYear;
use App\Support\ModuleMap;
use Illuminate\Support\Collection;

/**
 * What each contractor was paid over a year — the 1099-equivalent.
 *
 * Counts money that actually went out: released or paid. A draft is an intention and
 * an approved payment is a decision, and neither belongs on a statement of what
 * somebody received.
 */
class ContractorPaymentSummary
{
    /**
     * @return array{
     *     fiscal_year: string|null, from: string, to: string,
     *     contractors: array<int, array<string, mixed>>, total: float
     * }
     */
    public function summary(?int $fiscalYearId = null): array
    {
        $year = $fiscalYearId ? FiscalYear::find($fiscalYearId) : FiscalYear::current();

        $from = $year?->start_date?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $year?->end_date?->toDateString() ?? now()->endOfYear()->toDateString();

        $contractors = Beneficiary::contractors()->orderBy('name')->get();

        $rows = $contractors
            ->map(fn (Beneficiary $contractor): array => $this->rowFor($contractor, $from, $to))
            // Somebody engaged and not yet paid is not on a statement of what was paid.
            ->filter(fn (array $row): bool => $row['paid'] > 0)
            ->sortByDesc('paid')
            ->values()
            ->all();

        return [
            'fiscal_year' => $year?->name,
            'fiscal_year_id' => $year?->getKey(),
            'from' => $from,
            'to' => $to,
            'contractors' => $rows,
            'total' => round(array_sum(array_column($rows, 'paid')), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function rowFor(Beneficiary $contractor, string $from, string $to): array
    {
        $payments = $this->paymentsFor($contractor, $from, $to);

        return [
            'id' => $contractor->getKey(),
            'name' => $contractor->name,
            'engagement' => $contractor->engagement,
            'tax_identity' => $contractor->taxIdentity(),
            'payments' => $payments->count(),
            'paid' => round((float) $payments->sum('amount'), 2),
            'last_paid_on' => $payments->max('value_date')?->toDateString(),
        ];
    }

    /**
     * @return Collection<int, Payment>
     */
    private function paymentsFor(Beneficiary $contractor, string $from, string $to): Collection
    {
        return Payment::query()
            ->where('payable_type', ModuleMap::alias(Beneficiary::class))
            ->where('payable_id', $contractor->getKey())
            ->whereIn('status', [Payment::STATUS_EXPORTED, Payment::STATUS_PAID])
            ->whereNotNull('value_date')
            ->whereBetween('value_date', [$from, $to])
            ->get();
    }
}
