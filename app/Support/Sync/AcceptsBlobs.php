<?php

namespace App\Support\Sync;

use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use InvalidArgumentException;

/**
 * A workflow whose commands carry files — docs/desktop-mobile-plan.md §5a rule 5, built in O2.
 *
 * "Blobs ride behind their command": a photograph is never part of a command's batch. The command goes
 * first and lands; then each of its files is uploaded on its own, with its own ULID, to
 * `POST /api/v1/sync/{workflow}/blobs`, naming the command it belongs to. SyncBlobs does the idempotency,
 * the storing and the record; the workflow says what a file must look like and what to attach it to.
 */
interface AcceptsBlobs
{
    /**
     * Validation rules for an upload's own fields beside `id`, `command_id` and `captured_at` — the file
     * rule is where the size cap lives.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public function blobRules(): array;

    /** The directory on the company's `public` disk the files are stored under — the panel's own, so both see one set. */
    public function blobDirectory(): string;

    /**
     * Attach a stored file to what its command created or touched, through the module's own service, and
     * return what the device is told. Throw InvalidArgumentException for a refusal that will hold (the day
     * was signed off since) — the file is then deleted and the refusal recorded.
     *
     * @param  array{path: string, name: string, size: int, mime: string|null, hash: string}  $file
     * @param  array<string, mixed>  $fields  The upload's validated fields from blobRules(), without the file.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function attachBlob(SyncCommand $command, array $file, array $fields, User $user): array;
}
