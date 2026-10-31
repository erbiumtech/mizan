<?php

namespace App\Modules\Work\Integrations\Facts;

/** A branch created or deleted — §8.4. */
final class BranchFact extends Fact
{
    public function __construct(
        string $repositoryExternalId,
        array $author,
        public readonly string $name,
        public readonly bool $deleted,
        public readonly ?string $url,
    ) {
        parent::__construct($repositoryExternalId, $author);
    }
}
