<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One command a device sent to the sync inbox, and what the server answered — docs/desktop-mobile-plan.md §5a.
 *
 * Keyed on the ULID the device minted. Found again, the row *is* the answer: App\Support\Sync\SyncInbox
 * returns `result` with `http_status` and applies nothing. `applied` means the workflow took it;
 * `rejected` means the workflow refused it for a reason a human can read (a third punch, a day on
 * approved leave) and will refuse it the same way every time, so the refusal is kept too and the
 * device stops resending.
 */
class SyncCommand extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['client_id', 'workflow', 'user_id', 'token_id', 'payload', 'captured_at', 'received_at', 'status', 'http_status', 'result'];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'captured_at' => 'datetime',
        'received_at' => 'datetime',
        'http_status' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wasApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    /**
     * The per-command entry of a batch response, the same whether this is its first answer or a replay.
     *
     * @return array<string, mixed>
     */
    public function toClient(bool $replayed = false): array
    {
        return [
            'id' => $this->client_id,
            'status' => $replayed ? 'replayed' : $this->status,
            'http_status' => $this->http_status,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'received_at' => $this->received_at->toIso8601String(),
            'result' => $this->result,
        ];
    }
}
