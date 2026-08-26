<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\CommandUtterance;

/**
 * One kind of thing a command can turn into — `docs/ai-command-bot-plan.md` §7.
 *
 * "The bot routes; it does not learn every module's schema." A module registers a resolver, declares what
 * it can claim, contributes its own reference data to the prompt, and commits its own result. The router
 * knows none of that — it picks a resolver and hands over.
 *
 * Modelled on the command palette's `PaletteProvider`, and the shape is the same for the same reason: a
 * small contract that many modules implement beats one class that knows about all of them.
 *
 * **The prompt only ever carries one resolver's schema.** Routing happens before the model call, not
 * inside it, so a module this tenant has not licensed contributes nothing to the prompt at all — §7's
 * requirement, and the difference between gating and merely refusing afterwards, which would leak the
 * module list to anybody who read the reply.
 */
interface CommandResolver
{
    /** Stable key, stored on the utterance row. Never a class name — this travels in a column. */
    public function key(): string;

    /** What this resolver produces, for the confirmation and for logs. */
    public function label(): string;

    /**
     * Available to this tenant and this user, right now.
     *
     * Both halves matter and they are different questions: `modules()->enabled()` asks whether the company
     * bought it, the permission check asks whether this person may do it. A resolver that fails either is
     * not offered, not merely refused later.
     */
    public function isAvailable(): bool;

    /**
     * Words and patterns that hand an utterance to this resolver.
     *
     * Deliberately crude, and the honest reason is cost: routing with a second model call would read the
     * sentence properly and double the latency and spend of every command. Patterns plus a default
     * resolver is the cheap 95% — and when it routes wrong the user sees a proposal for the wrong kind of
     * thing and cancels, which is a visible failure rather than a silent one.
     *
     * @return array<int, string> regex fragments, matched case-insensitively against the raw utterance
     */
    public function claims(): array;

    /** Whether this is the resolver an unclaimed utterance falls back to. Exactly one must say yes. */
    public function isDefault(): bool;

    /**
     * This resolver's half of the system prompt — its reference data and its rules.
     *
     * Must be stable for the tenant across commands: it is the cacheable prefix, and anything that varies
     * per command belongs in the user turn instead.
     */
    public function promptSection(): string;

    /**
     * The slots this resolver needs, as JSON Schema properties.
     *
     * @return array{properties: array<string, mixed>, required: array<int, string>}
     */
    public function schemaProperties(): array;

    /**
     * The values a person may pick for a slot the parser could not fill, as `value => label`.
     *
     * **This is what makes a question worth asking.** The design has always preferred asking over
     * guessing, and that half was right — but the ask was a dead end: "What should this be filed under?"
     * appeared with nothing to answer it, so the only way forward was to retype the whole command using a
     * word the alias table happened to know. From the outside that is indistinguishable from the command
     * being ignored, which is exactly how it was reported.
     *
     * Returns `[]` for a slot this resolver cannot offer a closed list for — an amount is not a menu.
     *
     * @return array<int|string, string>
     */
    public function choices(string $slot): array;

    /** Turn the model's reply into a proposal — or into questions. Books nothing. */
    public function resolve(CommandUtterance $row, array $parsed): CommandInterpretation;

    /** Rebuild a pending proposal from its audit row, for the confirmation round trip. */
    public function rehydrate(CommandUtterance $row): ?CommandInterpretation;

    /**
     * Commit a complete proposal. The only method in this interface that changes anything.
     *
     * Returns whatever the module considers the result — a journal entry, a claim, a cost entry. The
     * router does not inspect it; the resolver describes it through {@see CommandInterpretation}.
     */
    public function commit(CommandInterpretation $interpretation): mixed;
}
