<?php

namespace App\Modules\ConstructionCosting\Support;

/**
 * One line-pair of a summary journal — `docs/construction-management-plan.md` §4.1's "per period per (GL account × cost
 * type)".
 *
 * A debit account, a credit account, an amount, and **the entry ids behind it**. That last field is what §4.1 calls the
 * batch link and what it says the whole approach depends on: "a summary posting without that link is a number in the
 * accounts nobody can explain, and should be treated as a defect rather than a shortcut."
 *
 * The ids are carried through the plan rather than re-derived at posting time so that what is previewed and what is
 * posted are provably the same set. A preview computed from one query and a posting from another can differ by whatever
 * somebody entered in between, and the difference would land in the accounts.
 */
readonly class PostingLine
{
    /** @param array<int, int> $entryIds */
    public function __construct(
        public string $purpose,
        public string $purposeLabel,
        public int $debitAccountId,
        public int $creditAccountId,
        public ?string $costType,
        public float $amount,
        public array $entryIds,
    ) {}

    public function entryCount(): int
    {
        return count($this->entryIds);
    }

    /**
     * What the journal line says.
     *
     * Names the cost type as well as the rule, because §4.1's grouping is by both and a reader of the general ledger
     * seeing four lines from one run needs to know why there are four.
     */
    public function memo(): string
    {
        return $this->purposeLabel
            .($this->costType ? ' — '.ucfirst($this->costType) : '')
            .' ('.$this->entryCount().' job cost entr'.($this->entryCount() === 1 ? 'y' : 'ies').')';
    }
}
