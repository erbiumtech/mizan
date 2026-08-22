<?php

namespace App\Modules\Construction\Services;

use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\DocumentRevision;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * The ISO 19650 four-state machine: work in progress → shared → published → archived.
 *
 * **In a service, never in a form** — `docs/construction-management-plan.md` §15 says so, and the reason is
 * worth stating: a rule enforced in a Filament form is bypassed by every other caller. An import, an artisan
 * command, a transmittal action or a future API would each walk straight past it, and nothing would report the
 * document that skipped its approval gate. A form validates *input*; this decides what is *allowed*.
 *
 * Three rules the standard cares about, and each has a real defect behind it:
 *
 *  - **A revision may not be shared while its approval is pending.** Sharing is what puts a drawing in front of
 *    other disciplines to design against; doing it before the gate clears means people co-ordinate to something
 *    that may yet be rejected.
 *  - **Published requires an approved revision.** Published is the state that means "build this".
 *  - **Published is the only state a construction-issue drawing may be in.** An inspection, an RFI answer or a
 *    punch item referencing a work-in-progress drawing is a defect waiting to be built — which is §15's own
 *    phrasing and the sharpest sentence in that section.
 *
 * Moving *backwards* is refused outright. A published drawing that quietly returns to work-in-progress is a
 * drawing somebody is already building from, and the honest act is a new revision.
 */
class DocumentStateMachine
{
    /**
     * Where each state may go next.
     *
     * Archived is reachable from anywhere — a container abandoned at work-in-progress is archived, not deleted,
     * because the register is the record of what existed — and is terminal.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        Document::STATE_WIP => [Document::STATE_SHARED, Document::STATE_ARCHIVED],
        Document::STATE_SHARED => [Document::STATE_PUBLISHED, Document::STATE_ARCHIVED],
        Document::STATE_PUBLISHED => [Document::STATE_ARCHIVED],
        Document::STATE_ARCHIVED => [],
    ];

    /**
     * The current revision, read from the column rather than through the relation.
     *
     * **Not `$document->currentRevision`, and this was a real bug rather than a precaution.** Eloquent caches a
     * loaded relation on the instance, so a second `issueRevision()` on the same object read the value from
     * before the first one — `null` — and superseded nothing. The register then held two revisions both marked
     * `issued`, which is precisely the failure ISO 19650's revision control exists to prevent: "a superseded one
     * is not in use" is unanswerable when two claim to be current.
     *
     * The `current_revision_id` attribute is updated in memory by `update()`, so reading it is safe; the
     * relation is what goes stale.
     */
    private function currentRevisionOf(Document $document): ?DocumentRevision
    {
        return $document->current_revision_id
            ? DocumentRevision::query()->find($document->current_revision_id)
            : null;
    }

    /** Whether a move is allowed at all, ignoring the approval gate. */
    public function canTransition(Document $document, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$document->cde_state] ?? [], true);
    }

    /**
     * Why this document cannot move to a state, or null when it can.
     *
     * Returns the reason rather than a boolean so the screen can say it. "You cannot publish this" with no
     * explanation is the kind of refusal people work around by creating a second document.
     */
    public function blocker(Document $document, string $to): ?string
    {
        if (! array_key_exists($to, Document::STATES)) {
            return "{$to} is not a state.";
        }

        if ($document->cde_state === $to) {
            return 'It is already in that state.';
        }

        if (! $this->canTransition($document, $to)) {
            return sprintf(
                'A %s container cannot move to %s. Issue a new revision instead of moving it back.',
                strtolower(Document::STATES[$document->cde_state]),
                strtolower(Document::STATES[$to]),
            );
        }

        $revision = $this->currentRevisionOf($document);

        if ($to === Document::STATE_SHARED && $revision?->isApprovalPending()) {
            return 'Its current revision is awaiting approval. Sharing it now would have other disciplines '
                .'design against something that may still be rejected.';
        }

        if ($to === Document::STATE_PUBLISHED) {
            if ($revision === null) {
                return 'It has no revision to publish.';
            }

            if ($revision->approval_status === DocumentRevision::APPROVAL_PENDING) {
                return 'Its current revision is awaiting approval.';
            }

            if ($revision->approval_status === DocumentRevision::APPROVAL_REJECTED) {
                return 'Its current revision was rejected. Issue a corrected one.';
            }
        }

        return null;
    }

    /**
     * Move the document, refusing loudly with the reason.
     *
     * The revision records the state it was issued at, which is what lets the register answer "what was
     * published on the day this was built" after the document has moved on.
     */
    public function transition(Document $document, string $to): Document
    {
        if ($blocker = $this->blocker($document, $to)) {
            throw new InvalidArgumentException(
                sprintf('%s cannot move to %s. %s', $document->information_container_id, $to, $blocker)
            );
        }

        return TenantTransaction::run(function () use ($document, $to): Document {
            $document->update(['cde_state' => $to]);

            $this->currentRevisionOf($document)?->update(['cde_state_at_issue' => $to]);

            return $document->refresh();
        });
    }

    /**
     * Issue a new revision, superseding the one before it.
     *
     * Two things happen that a plain create would miss. The previous revision becomes `superseded`, so the
     * register has exactly one current version and "is a superseded drawing in use" is answerable. And the
     * document's `current_revision_id` moves — without which every screen keeps showing the old sheet while
     * the new one sits in the table.
     *
     * A re-issue whose file is byte-identical to the one it supersedes is **allowed and reported**, not
     * refused: it happens for real when a title block changes outside the file, and the hash comparison is
     * what lets a reviewer skip it rather than re-check forty sheets.
     */
    public function issueRevision(Document $document, array $attributes): DocumentRevision
    {
        return TenantTransaction::run(function () use ($document, $attributes): DocumentRevision {
            $previous = $this->currentRevisionOf($document);

            $revision = $document->revisions()->create($attributes + [
                'status' => DocumentRevision::STATUS_ISSUED,
                'cde_state_at_issue' => $document->cde_state,
            ]);

            if ($previous && $previous->getKey() !== $revision->getKey()) {
                $previous->update(['status' => DocumentRevision::STATUS_SUPERSEDED]);
            }

            $document->update(['current_revision_id' => $revision->getKey()]);

            return $revision->refresh();
        });
    }

    /**
     * Whether this revision is the same file as the one it superseded.
     *
     * The question a reviewer asks on receiving P03, and the register answers it for free because the sha256
     * is stored. Null when either side has no hash — an honest "cannot tell" rather than a misleading false.
     */
    public function isUnchangedReissue(DocumentRevision $revision): ?bool
    {
        $previous = $revision->document
            ->revisions()
            ->where('id', '<', $revision->getKey())
            ->orderByDesc('id')
            ->first();

        if ($previous === null || blank($revision->file_hash) || blank($previous->file_hash)) {
            return null;
        }

        return $revision->file_hash === $previous->file_hash;
    }
}
