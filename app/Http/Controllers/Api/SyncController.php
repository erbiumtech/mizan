<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Support\Sync\AcceptsBlobs;
use App\Support\Sync\SyncBlobs;
use App\Support\Sync\SyncInbox;
use App\Support\Sync\SyncWorkflows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The sync endpoints — docs/desktop-mobile-plan.md §5a, O1. `POST /api/v1/sync/{workflow}` takes a batch
 * of commands a device captured offline; `GET /api/v1/sync/{workflow}/changes?since=` is the incremental
 * read that keeps the device's copy current. Both are generic over App\Support\Sync\SyncWorkflow; the
 * routes are mounted by the module that owns each workflow, under its own module gate, which is why
 * `{workflow}` arrives as a route default rather than a free segment.
 *
 * Reached with a bearer token from the API and with the session from the panel (the module's web
 * route) — the same inbox either way, because the offline page that captures a punch has the session
 * and nothing else.
 */
class SyncController extends Controller
{
    /** Commands per batch. A device with more sends several; the inbox is idempotent, so the split costs nothing. */
    public const BATCH_LIMIT = 100;

    /** Rows per page of changes, and the ceiling a client may ask for. */
    public const PAGE_DEFAULT = 200;

    public const PAGE_LIMIT = 500;

    /** Crockford base32, 26 characters, first one 0–7 so the timestamp fits 48 bits — a ULID. */
    private const ULID = '/^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$/';

    public function receive(Request $request, SyncWorkflows $workflows, SyncInbox $inbox): JsonResponse
    {
        $workflow = $this->workflowOf($request);

        abort_unless($workflows->has($workflow), 404, 'No such sync workflow.');

        $flow = $workflows->for($workflow);

        $rules = [
            'commands' => ['required', 'array', 'min:1', 'max:'.self::BATCH_LIMIT],
            'commands.*.id' => ['required', 'string', 'regex:'.self::ULID],
            'commands.*.payload' => ['required', 'array'],
            'commands.*.captured_at' => ['nullable', 'date'],
        ];

        foreach ($flow->rules() as $field => $rule) {
            $rules['commands.*.payload.'.$field] = $rule;
        }

        $data = $request->validate($rules);

        $token = $request->user()->currentAccessToken();

        $received = $inbox->receive(
            $flow,
            $request->user(),
            $data['commands'],
            $token instanceof PersonalAccessToken ? (int) $token->getKey() : null,
        );

        return ApiResponse::success(['workflow' => $flow->key(), ...$received]);
    }

    /**
     * One file behind one command — rule 5, O2. `id` is the file's own ULID, `command_id` the command it rides
     * behind; the rest of the fields, and the size cap, are the workflow's. Answered with the inbox's six-key
     * entry, at 200 whatever it says, exactly as a batch is: the entry's own `http_status` is the outcome.
     */
    public function blob(Request $request, SyncWorkflows $workflows, SyncBlobs $blobs): JsonResponse
    {
        $workflow = $this->workflowOf($request);

        abort_unless($workflows->has($workflow), 404, 'No such sync workflow.');

        $flow = $workflows->for($workflow);

        abort_unless($flow instanceof AcceptsBlobs, 404, 'This workflow carries no files.');

        $data = $request->validate([
            'id' => ['required', 'string', 'regex:'.self::ULID],
            'command_id' => ['required', 'string', 'regex:'.self::ULID],
            'captured_at' => ['nullable', 'date'],
            ...$flow->blobRules(),
        ]);

        $token = $request->user()->currentAccessToken();

        $entry = $blobs->receive(
            $flow,
            $request->user(),
            $data['id'],
            $data['command_id'],
            $data['captured_at'] ?? null,
            $request->file('file'),
            collect($data)->except(['id', 'command_id', 'captured_at', 'file'])->all(),
            $token instanceof PersonalAccessToken ? (int) $token->getKey() : null,
        );

        return ApiResponse::success(['workflow' => $flow->key(), 'result' => $entry]);
    }

    public function changes(Request $request, SyncWorkflows $workflows): JsonResponse
    {
        $workflow = $this->workflowOf($request);

        abort_unless($workflows->has($workflow), 404, 'No such sync workflow.');

        $data = $request->validate([
            'since' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::PAGE_LIMIT],
        ]);

        $changes = $workflows->for($workflow)->changesSince(
            $data['since'] ?? null,
            $request->user(),
            (int) ($data['limit'] ?? self::PAGE_DEFAULT),
        );

        return ApiResponse::success(['workflow' => $workflow, ...$changes->toClient()]);
    }

    /**
     * The workflow a route names, by name. It arrives as a route default, and a session route also
     * carries `{company}`: Laravel hands scalar parameters to a controller in route order, not by name,
     * so a `string $workflow` argument received the company's slug there and every panel replay was a
     * 404 for an unregistered workflow.
     */
    private function workflowOf(Request $request): string
    {
        return (string) $request->route('workflow');
    }
}
