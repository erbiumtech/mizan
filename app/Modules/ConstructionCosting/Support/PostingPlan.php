<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * What a period would post, and what it would leave behind — `docs/construction-management-plan.md` §4.1.
 *
 * **The `skipped` half is the point of this class existing.** A posting service that posted what it could and returned
 * a count would leave a company that had never nominated a burden-absorption account with burden accumulating as
 * `pending` forever — which is §7.3's exact failure: "job cost exceeds GL cost by exactly the burden, growing every
 * month, with no error anywhere". The absence has to come back with the result, in words, attached to the accounts that
 * are missing.
 *
 * **And posting what it can is the right behaviour, not a compromise.** Refusing the whole run over one missing account
 * would leave *everything* pending, which makes §4.2's reconciliation unreadable rather than merely incomplete. The
 * defence against the quiet version is that `skipped` travels with every result, the page prints it, and §4.2's report
 * carries "entries still pending by age" as a named cause — so a company that ignores this sees it again, monthly,
 * getting older.
 */
readonly class PostingPlan
{
    /**
     * @param  array<int, PostingLine>  $lines
     * @param  array<string, array{count: int, amount: float, reason: string}>  $skipped  keyed by rule
     */
    public function __construct(
        public string $periodStart,
        public array $lines,
        public array $skipped,
        public float $total,
        public int $entryCount,
    ) {}

    public function hasSomethingToPost(): bool
    {
        return $this->lines !== [];
    }

    public function hasSkipped(): bool
    {
        return $this->skipped !== [];
    }

    public function skippedCount(): int
    {
        return array_sum(array_column($this->skipped, 'count'));
    }

    public function skippedAmount(): float
    {
        return round(array_sum(array_column($this->skipped, 'amount')), 2);
    }

    /**
     * The whole plan as a sentence, absence included.
     *
     * Written so that the *good* case is short and the incomplete case is long. A company whose chart is set up reads
     * one line a month; a company missing an account reads what is missing every month until it fixes it.
     */
    public function describe(): string
    {
        if (! $this->hasSomethingToPost() && ! $this->hasSkipped()) {
            return 'Nothing to post: no cost entry in this period is awaiting the general ledger. Mirrored entries were '
                .'posted by the document that caused them, and memo entries deliberately never post.';
        }

        $parts = [];

        if ($this->hasSomethingToPost()) {
            $parts[] = number_format($this->total, 2).' across '.count($this->lines).' journal line(s) from '
                .$this->entryCount.' cost entr'.($this->entryCount === 1 ? 'y' : 'ies').'.';
        }

        foreach ($this->skipped as $reason) {
            $parts[] = $reason['reason'].' '.$reason['count'].' entr'.($reason['count'] === 1 ? 'y' : 'ies')
                .' worth '.number_format($reason['amount'], 2).' stay pending.';
        }

        return implode(' ', $parts);
    }
}
