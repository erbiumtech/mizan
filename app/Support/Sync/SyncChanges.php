<?php

namespace App\Support\Sync;

/**
 * A page of an incremental read — docs/desktop-mobile-plan.md §5a rule 3.
 *
 * `rows` are the documents as the client should hold them, `deleted` the ids of ones gone since the
 * cursor (from the soft deletes a model carries; a workflow over a model that deletes for good has
 * none to give and says so in its own documentation), `cursor` what the client sends next time.
 * `hasMore` tells it to come straight back rather than wait for the next sync.
 */
final class SyncChanges
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int|string>  $deleted
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $deleted,
        public readonly ?string $cursor,
        public readonly bool $hasMore,
    ) {}

    /** @return array<string, mixed> */
    public function toClient(): array
    {
        return [
            'data' => $this->rows,
            'deleted' => array_values($this->deleted),
            'cursor' => $this->cursor,
            'has_more' => $this->hasMore,
        ];
    }
}
