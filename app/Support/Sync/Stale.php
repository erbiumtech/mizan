<?php

namespace App\Support\Sync;

use RuntimeException;

/**
 * A change made against a version the document has since moved past — docs/desktop-mobile-plan.md §5a
 * rule 4, built in O2.
 *
 * Thrown by a workflow *after* it has kept the server's value and filed the device's change for a human
 * to see. Unlike a refusal, those writes stand: the inbox catches this inside the command's transaction,
 * so the trail commits with the row that records the command as `stale` (409). A replay answers from
 * that row, so the device is told once and stops resending — the change is not lost, it is waiting on a
 * person, which is exactly what the device should show.
 */
final class Stale extends RuntimeException
{
    /** @param  array<string, mixed>  $result  What the device is told: the reason, and the document as the server holds it. */
    public function __construct(string $message, public readonly array $result = [])
    {
        parent::__construct($message);
    }
}
