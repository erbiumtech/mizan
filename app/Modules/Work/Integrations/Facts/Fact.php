<?php

namespace App\Modules\Work\Integrations\Facts;

/**
 * One thing a delivery says — docs/project-management-plan.md §8.3.
 *
 * Parsing turns each provider's shape into one of three internal facts: a commit mentioning
 * keys, a branch created or deleted, a pull request opened, updated, merged or closed. Everything
 * after this line is provider-free, which is the whole reason these exist as classes rather than
 * as the provider's payload handed along.
 */
abstract class Fact
{
    /** @param  array{username: ?string, id: ?string, email: ?string}  $author */
    public function __construct(
        public readonly string $repositoryExternalId,
        public readonly array $author,
    ) {}
}
