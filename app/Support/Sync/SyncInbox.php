<?php

namespace App\Support\Sync;

use App\Modules\Core\Models\SyncCommand;
use App\Modules\Core\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The server inbox — docs/desktop-mobile-plan.md §5a rule 2.
 *
 * A batch is applied strictly in the order it arrives, one transaction per command, so a connection
 * that dies half way leaves every command before the break applied and recorded and every one after
 * it untouched; the device resends the whole batch and the recorded ones answer from their rows. The
 * device's clock (`captured_at`) is kept and never consulted: `received_at` is ours and is the order.
 *
 * Three answers per command. *Applied*: the workflow took it, here is the result. *Rejected*: the
 * workflow refused it for a reason that will hold on every replay — recorded with the refusal, so the
 * device shows the reason and stops. *Failed*: something of ours threw; nothing is recorded, the
 * batch stops there, and the device tries again later. A command whose ULID another user already
 * sent is a *conflict* and is neither applied nor recorded: a ULID is minted on one device for one
 * person, and two people sharing one is a client bug worth hearing about, not a replay.
 */
class SyncInbox
{
    /**
     * @param  array<int, array{id: string, payload: array<string, mixed>, captured_at?: string|null}>  $commands
     * @return array{results: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function receive(SyncWorkflow $workflow, User $user, array $commands, ?int $tokenId = null): array
    {
        $results = [];
        $summary = ['applied' => 0, 'replayed' => 0, 'rejected' => 0, 'conflict' => 0, 'failed' => 0, 'skipped' => 0];
        $stopped = false;

        foreach ($commands as $command) {
            $clientId = strtoupper((string) $command['id']);

            if ($stopped) {
                $results[] = ['id' => $clientId, 'status' => 'skipped', 'http_status' => 0, 'result' => null];
                $summary['skipped']++;

                continue;
            }

            $entry = $this->one($workflow, $user, $clientId, $command, $tokenId);
            $results[] = $entry;
            $summary[$entry['status']]++;

            if ($entry['status'] === 'failed') {
                $stopped = true;
            }
        }

        return ['results' => $results, 'summary' => $summary];
    }

    /**
     * @param  array{id: string, payload: array<string, mixed>, captured_at?: string|null}  $command
     * @return array<string, mixed>
     */
    private function one(SyncWorkflow $workflow, User $user, string $clientId, array $command, ?int $tokenId): array
    {
        if ($existing = $this->find($clientId)) {
            return $this->answerFromRow($existing, $user);
        }

        $row = new SyncCommand([
            'client_id' => $clientId,
            'workflow' => $workflow->key(),
            'user_id' => $user->getKey(),
            'token_id' => $tokenId,
            'payload' => $command['payload'],
            'captured_at' => isset($command['captured_at']) ? Carbon::parse($command['captured_at']) : null,
            'received_at' => Carbon::now(),
        ]);

        try {
            // The workflow's writes and the row that says they happened commit together, or neither does.
            DB::transaction(function () use ($workflow, $user, $row): void {
                try {
                    $result = $workflow->apply($row, $user);
                    $row->fill(['status' => SyncCommand::STATUS_APPLIED, 'http_status' => 200, 'result' => $result]);
                } catch (InvalidArgumentException $e) {
                    // The workflow's own partial writes, if any, roll back with the savepoint; the
                    // refusal is then recorded so a replay answers the same way without re-asking.
                    throw new Rejected($e->getMessage());
                }

                $row->save();
            });
        } catch (Rejected $rejected) {
            $row->fill(['status' => SyncCommand::STATUS_REJECTED, 'http_status' => 422, 'result' => ['message' => $rejected->getMessage()]]);

            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                return $this->answerFromRow($this->find($clientId), $user);
            }
        } catch (UniqueConstraintViolationException) {
            // The same command arrived twice at once; the other request's row is the answer.
            return $this->answerFromRow($this->find($clientId), $user);
        } catch (Throwable $e) {
            report($e);

            return ['id' => $clientId, 'status' => 'failed', 'http_status' => 500, 'result' => ['message' => 'The server could not apply this command; it will be retried.']];
        }

        return $row->toClient();
    }

    private function find(string $clientId): ?SyncCommand
    {
        return SyncCommand::query()->where('client_id', $clientId)->first();
    }

    /** @return array<string, mixed> */
    private function answerFromRow(SyncCommand $row, User $user): array
    {
        if ((int) $row->user_id !== (int) $user->getKey()) {
            return ['id' => $row->client_id, 'status' => 'conflict', 'http_status' => 409, 'result' => ['message' => 'Another account already sent a command with this id.']];
        }

        return $row->toClient(replayed: true);
    }
}
