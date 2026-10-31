<?php

namespace App\Modules\Work\Integrations\Facts;

use Illuminate\Support\Carbon;

/** A pull request opened, updated, merged or closed — §8.4. */
final class PullRequestFact extends Fact
{
    public const OPENED = 'opened';

    public const UPDATED = 'updated';

    public const MERGED = 'merged';

    public const CLOSED = 'closed';

    public function __construct(
        string $repositoryExternalId,
        array $author,
        public readonly int $number,
        public readonly string $title,
        public readonly string $headBranch,
        public readonly string $action,
        public readonly ?string $url,
        public readonly ?Carbon $mergedAt,
        public readonly ?string $checksState = null,
    ) {
        parent::__construct($repositoryExternalId, $author);
    }
}
