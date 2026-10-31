<?php

namespace App\Support\Sync;

use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * The blob queue's server end — docs/desktop-mobile-plan.md §5a rule 5, built in O2.
 *
 * One upload is one file for one command the device already sent. It is idempotent the way a command is,
 * on a ULID the device minted for the file, and recorded in the same `sync_commands` table under the
 * workflow's key with `:blob` after it — so a photograph whose answer was lost on the way back is
 * answered from its row the second time and stored once, and the receipt sits beside the command it
 * rode behind.
 *
 * The answer is the inbox's six-key entry. *Applied*: stored and attached. *Rejected* (recorded): the
 * command it names is not one this person sent and the server applied — a refusal that will hold, so the
 * device drops the file — or the workflow refused to attach it. *Failed* (not recorded): ours, retry.
 * The file is written to the company's `public` disk first and deleted again on every path that does
 * not end in an attached photograph, so a refusal leaves nothing behind.
 */
class SyncBlobs
{
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function receive(
        SyncWorkflow&AcceptsBlobs $workflow,
        User $user,
        string $blobId,
        string $commandId,
        ?string $capturedAt,
        UploadedFile $upload,
        array $fields,
        ?int $tokenId = null,
    ): array {
        $blobId = strtoupper($blobId);
        $commandId = strtoupper($commandId);

        if ($existing = SyncCommand::query()->where('client_id', $blobId)->first()) {
            return $this->answerFromRow($existing, $user);
        }

        $row = new SyncCommand([
            'client_id' => $blobId,
            'workflow' => $workflow->key().':blob',
            'user_id' => $user->getKey(),
            'token_id' => $tokenId,
            'payload' => ['command_id' => $commandId, 'file_name' => $upload->getClientOriginalName(), 'file_size' => $upload->getSize(), ...$fields],
            'captured_at' => $capturedAt !== null ? Carbon::parse($capturedAt) : null,
            'received_at' => Carbon::now(),
        ]);

        $parent = SyncCommand::query()->where('client_id', $commandId)->first();
        $refusal = match (true) {
            $parent === null, (int) $parent->user_id !== (int) $user->getKey(), $parent->workflow !== $workflow->key() => 'This photograph names a command this device never sent. It cannot be attached to anything.',
            $parent->status !== SyncCommand::STATUS_APPLIED => 'The entry this photograph belongs to was not recorded, so there is nothing to attach it to.',
            default => null,
        };

        if ($refusal !== null) {
            return $this->record($row, SyncCommand::STATUS_REJECTED, 422, ['message' => $refusal], $user);
        }

        $disk = Storage::disk('public');
        $path = $disk->putFile($workflow->blobDirectory(), $upload);

        if ($path === false) {
            return $this->failed($row);
        }

        $file = [
            'path' => $path,
            'name' => $upload->getClientOriginalName(),
            'size' => (int) $upload->getSize(),
            'mime' => $upload->getMimeType(),
            'hash' => hash_file('sha256', $upload->getRealPath()) ?: '',
        ];

        try {
            DB::transaction(function () use ($workflow, $parent, $file, $fields, $user, $row): void {
                try {
                    $result = $workflow->attachBlob($parent, $file, $fields, $user);
                } catch (InvalidArgumentException $e) {
                    throw new Rejected($e->getMessage());
                }

                $row->fill(['status' => SyncCommand::STATUS_APPLIED, 'http_status' => 200, 'result' => $result])->save();
            });
        } catch (Rejected $rejected) {
            $disk->delete($path);

            return $this->record($row, SyncCommand::STATUS_REJECTED, 422, ['message' => $rejected->getMessage()], $user);
        } catch (UniqueConstraintViolationException) {
            // The same file arrived twice at once; the other request stored and attached it.
            $disk->delete($path);

            return $this->answerFromRow(SyncCommand::query()->where('client_id', $blobId)->firstOrFail(), $user);
        } catch (Throwable $e) {
            $disk->delete($path);
            report($e);

            return $this->failed($row);
        }

        return $row->toClient();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(SyncCommand $row, string $status, int $httpStatus, array $result, User $user): array
    {
        $row->fill(['status' => $status, 'http_status' => $httpStatus, 'result' => $result]);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            return $this->answerFromRow(SyncCommand::query()->where('client_id', $row->client_id)->firstOrFail(), $user);
        }

        return $row->toClient();
    }

    /** @return array<string, mixed> */
    private function failed(SyncCommand $row): array
    {
        return [
            'id' => $row->client_id,
            'status' => 'failed',
            'http_status' => 500,
            'captured_at' => $row->captured_at?->toIso8601String(),
            'received_at' => $row->received_at?->toIso8601String(),
            'result' => ['message' => 'The server could not store this photograph; it will be retried.'],
        ];
    }

    /** @return array<string, mixed> */
    private function answerFromRow(SyncCommand $row, User $user): array
    {
        if ((int) $row->user_id !== (int) $user->getKey()) {
            return [
                'id' => $row->client_id,
                'status' => 'conflict',
                'http_status' => 409,
                'captured_at' => null,
                'received_at' => null,
                'result' => ['message' => 'Another account already sent a file with this id.'],
            ];
        }

        return $row->toClient(replayed: true);
    }
}
