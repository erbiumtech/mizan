<?php

namespace App\Modules\Work\Integrations\Facts;

use Illuminate\Support\Carbon;

/** A commit, with its whole message so smart-commit commands can be read from it — §8.4. */
final class CommitFact extends Fact
{
    public function __construct(
        string $repositoryExternalId,
        array $author,
        public readonly string $sha,
        public readonly string $message,
        public readonly ?Carbon $committedAt,
        public readonly ?string $url,
    ) {
        parent::__construct($repositoryExternalId, $author);
    }

    public function firstLine(): string
    {
        return trim(strtok($this->message, "\n") ?: $this->message);
    }
}
