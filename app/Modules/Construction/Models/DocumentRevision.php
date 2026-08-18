<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issued version of a document — §15.
 *
 * The `file_hash` is a sha256 and earns its place: it is the only reliable answer to "is this the same
 * drawing", and it is what catches a **re-issue with no changes** — which is otherwise invisible and wastes
 * every reviewer's time on the distribution list.
 *
 * `suitability_code` is a string against the job's naming convention rather than an enum, because S0–S7 with
 * A1–A5 and B1–B5 is the UK ISO 19650-2 set while AIA-land issues "for construction", "for approval" and
 * "as-built" — an enum fails on the second project.
 */
class DocumentRevision extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_SUPERSEDED = 'superseded';

    public const APPROVAL_NOT_REQUIRED = 'not_required';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $table = 'construction_document_revisions';

    protected $fillable = [
        'document_id', 'revision', 'suitability_code', 'cde_state_at_issue', 'status', 'reason_for_issue',
        'issued_on', 'issued_by', 'received_on',
        'file_path', 'file_name', 'file_size', 'file_mime', 'file_hash',
        'scale', 'sheet_size', 'drawn_by', 'checked_by',
        'approval_status', 'approver_id', 'approved_at', 'approval_comments',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'received_on' => 'date',
        'approved_at' => 'datetime',
        'file_size' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'approval_status' => self::APPROVAL_NOT_REQUIRED,
        'cde_state_at_issue' => Document::STATE_WIP,
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isApprovalPending(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    /**
     * Whether this revision's file is byte-identical to another's.
     *
     * The question a reviewer actually asks on receiving revision P03 — "has anything changed since P02" — and
     * the register can answer it for free because the hash is stored.
     */
    public function isSameFileAs(?self $other): bool
    {
        return $other !== null
            && filled($this->file_hash)
            && $this->file_hash === $other->file_hash;
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }
}
