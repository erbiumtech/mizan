<?php

namespace App\Support\Ai;

/**
 * One capability, deliberately: turn a sentence into a fixed set of fields.
 *
 * Narrow because of what it withholds. A caller that could hand the model tools
 * could hand it the tool that books money, and `docs/ai-command-bot-plan.md` §4
 * spends a paragraph on why it must not — the model resolves words into values
 * and a service the model cannot reach decides what to do with them.
 *
 * The fake in this namespace is what lets every consumer be tested without a
 * network call or a key.
 */
interface StructuredModel
{
    /**
     * Extract `$schema`-shaped fields from `$utterance`.
     *
     * `$system` carries the stable, cacheable half — the tenant's category list
     * and the rules. `$utterance` is what the person actually said and must be
     * the only volatile part, or the prompt cache is invalidated on every call.
     *
     * @param  array<string, mixed>  $schema  JSON Schema the reply must satisfy
     * @return array<string, mixed>
     *
     * @throws ModelUnavailable when the model could not be reached or replied unusably
     */
    public function extract(string $system, string $utterance, array $schema): array;

    /** Whether a call would even be attempted — no key, no bot. */
    public function isConfigured(): bool;
}
