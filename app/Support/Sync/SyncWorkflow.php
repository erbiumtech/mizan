<?php

namespace App\Support\Sync;

use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use InvalidArgumentException;

/**
 * One kind of offline capture — docs/desktop-mobile-plan.md §5a. The engine (SyncInbox) is the same
 * for all of them; a workflow says what a command's payload must look like, how it is applied, and
 * what has changed since a cursor. O1 ships attendance punches; O2–O4 add theirs by implementing this
 * and registering in SyncWorkflows, without the inbox or its controller changing.
 */
interface SyncWorkflow
{
    /** The `{workflow}` segment of `POST /api/v1/sync/{workflow}`. */
    public function key(): string;

    /** The module whose licence gates this workflow — the `module:` middleware on its routes. */
    public function module(): string;

    /**
     * Validation rules for one command's payload, keyed without the `payload.` prefix.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array;

    /**
     * Apply one command and return what the client is told. Throw InvalidArgumentException for a
     * refusal that will hold however often the command is replayed (recorded as `rejected`, 422);
     * anything else thrown is a failure of ours, not recorded, and the device will try again.
     *
     * Rule 6: whatever this does, it does through the same service the online path uses.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function apply(SyncCommand $command, User $user): array;

    /** Rows changed since a cursor, within what the user may see, oldest first — rule 3. */
    public function changesSince(?string $cursor, User $user, int $limit): SyncChanges;
}
