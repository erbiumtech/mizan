<?php

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Models\EmployeeDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which documents are about to lapse, and which of those anybody has been told about.
 *
 * **Notifies on transitions, never on every run.** The health-check alerts taught that
 * lesson here already: a daily job that mails the same person the same warning for
 * thirty days trains them to filter it, and then the one that mattered is filtered too.
 *
 * `expiry_notified_at_days` records the threshold last warned at, so crossing 60 days
 * warns once, crossing 30 warns again, and the twenty-nine days in between are silent.
 */
class DocumentExpiryCheck
{
    /**
     * Documents that have newly crossed a threshold.
     *
     * @return Collection<int, array{document: EmployeeDocument, days: int, threshold: int}>
     */
    public function due(string|Carbon|null $asOf = null): Collection
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $thresholds = $this->thresholds();

        return EmployeeDocument::query()
            ->expiring()
            ->with('employee')
            ->get()
            ->map(function (EmployeeDocument $document) use ($asOf, $thresholds): ?array {
                $days = $document->daysUntilExpiry($asOf);

                if ($days === null) {
                    return null;
                }

                // The tightest threshold this document has now crossed. Expired
                // documents fall through to the smallest one and keep being reported
                // until somebody deals with them — an expired visa is not a warning
                // that stops being true.
                $crossed = null;

                foreach ($thresholds as $threshold) {
                    if ($days <= $threshold) {
                        $crossed = $threshold;
                    }
                }

                if ($crossed === null) {
                    return null;
                }

                // Already warned at this threshold or a tighter one: silent.
                if ($document->expiry_notified_at_days !== null
                    && $document->expiry_notified_at_days <= $crossed) {
                    return null;
                }

                return ['document' => $document, 'days' => $days, 'threshold' => $crossed];
            })
            ->filter()
            ->values();
    }

    /** Record that a threshold has been warned about, so the next run is silent. */
    public function markNotified(EmployeeDocument $document, int $threshold): void
    {
        $document->update(['expiry_notified_at_days' => $threshold]);
    }

    /**
     * Reset the warning state when an expiry moves.
     *
     * A renewed passport is a new deadline, and it has to be able to warn again — the
     * transition rule would otherwise silence the document for ever after one warning.
     */
    public function resetOnRenewal(EmployeeDocument $document): void
    {
        $document->update(['expiry_notified_at_days' => null]);
    }

    /** @return array<int, int> descending, so the loop above lands on the tightest crossed */
    private function thresholds(): array
    {
        $thresholds = (array) config('lifecycle.document_expiry_thresholds', [60, 30, 7]);

        rsort($thresholds);

        return array_map('intval', $thresholds);
    }
}
