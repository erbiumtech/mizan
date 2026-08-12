<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Which invoices FBR does not agree with us about.
 *
 * This exists because a push integration fails silently. The old FBR surface in
 * this codebase is a *pull*: somebody picks a month, downloads a file, and
 * nothing has happened until they act — a file that never gets downloaded is a
 * non-event. Reporting is the opposite. The application transmits on its own,
 * and a submission that never lands looks exactly like a working system from
 * every screen that already exists.
 *
 * So the report comes before the transmission, not after it. Each finding below
 * is a way for the books and FBR to be out of step, and each one is invisible
 * without this.
 */
class FbrReconciliation
{
    /**
     * Is this company reporting at all? Everything here means something
     * different when it is off — an unreported invoice is correct, not a gap.
     */
    public function enabled(): bool
    {
        return (bool) setting('fbr.enabled', false);
    }

    /**
     * Every finding, keyed by kind, each with the invoices behind it.
     *
     * Empty groups are dropped rather than shown as zero: the page is a list of
     * things to do, and four reassuring zeroes make the one real row harder to
     * see, not easier.
     *
     * @return array<string, array{label: string, explanation: string, invoices: Collection}>
     */
    public function findings(): array
    {
        $groups = [
            'rejected' => [
                'label' => 'Refused by FBR',
                'explanation' => 'FBR answered and said no. The invoice exists in the books and does not exist '
                    .'to FBR. Fix what it objected to and resubmit — the reason is on the latest submission.',
                'invoices' => $this->rejected(),
            ],
            'stuck' => [
                'label' => 'Submitted, never answered',
                'explanation' => 'Sent to FBR and still waiting well past the point where an answer was due. '
                    .'These may or may not have landed, which is why a blind retry is the wrong move.',
                'invoices' => $this->stuck(),
            ],
            'accepted_without_reference' => [
                'label' => 'Accepted with no reference number',
                'explanation' => 'Marked accepted but carrying no IRN, so nothing can be verified against FBR '
                    .'and the invoice cannot prove its own compliance. Treat as unreported until an IRN is on it.',
                'invoices' => $this->acceptedWithoutReference(),
            ],
            'unreported' => [
                'label' => 'Issued but never reported',
                'explanation' => 'Live invoices this company should be reporting and has not. This is the group '
                    .'that carries the compliance exposure.',
                'invoices' => $this->unreported(),
            ],
        ];

        return array_filter($groups, fn (array $group): bool => $group['invoices']->isNotEmpty());
    }

    public function total(): int
    {
        return array_sum(array_map(
            fn (array $group): int => $group['invoices']->count(),
            $this->findings(),
        ));
    }

    public function rejected(): Collection
    {
        return $this->base()->where('fbr_status', Invoice::FBR_REJECTED)->get();
    }

    /**
     * In flight for longer than anything is plausibly in flight.
     *
     * Measured from `updated_at` rather than a submission timestamp, so an
     * invoice whose row was written but whose submission never was still counts
     * — that gap is exactly the failure this looks for.
     */
    public function stuck(): Collection
    {
        $cutoff = Carbon::now()->subHours((int) setting('fbr.stale_submission_hours', 24));

        return $this->base()
            ->whereIn('fbr_status', [Invoice::FBR_PENDING, Invoice::FBR_SUBMITTED])
            ->where('updated_at', '<', $cutoff)
            ->get();
    }

    public function acceptedWithoutReference(): Collection
    {
        return $this->base()
            ->where('fbr_status', Invoice::FBR_ACCEPTED)
            ->where(fn ($q) => $q->whereNull('fbr_irn')->orWhere('fbr_irn', ''))
            ->get();
    }

    /**
     * Live sale invoices carrying `not_required` while the company is reporting.
     *
     * Only meaningful with reporting switched on, and returns nothing when it is
     * off — otherwise every company in this application would be told its entire
     * invoice history is a compliance gap, which for a company below the
     * threshold is false and alarming.
     *
     * Sales only. A purchase invoice is somebody else's obligation to report.
     */
    public function unreported(): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        return $this->base()
            ->where('kind', Invoice::KIND_SALE)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_PAID])
            ->where(fn ($q) => $q->whereNull('fbr_status')->orWhere('fbr_status', Invoice::FBR_NOT_REQUIRED))
            ->get();
    }

    /**
     * Drafts and voids are excluded everywhere: a draft was never issued, and a
     * void has been withdrawn. Neither is something FBR is owed.
     */
    private function base()
    {
        return Invoice::query()
            ->with('contact')
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');
    }
}
