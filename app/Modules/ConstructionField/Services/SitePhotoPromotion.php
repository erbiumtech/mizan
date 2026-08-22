<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\DocumentRevision;
use App\Modules\Construction\Models\NamingConvention;
use App\Modules\Construction\Services\DocumentStateMachine;
use App\Modules\ConstructionField\Models\DailyLogPhoto;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Promoting a site photograph into the ISO 19650 register — `docs/construction-management-plan.md` §16.1.
 *
 * **§16.1 keeps photographs out of the register and then names one exception, and both halves matter.** Out, because
 * "a site photo has no revision, no suitability code and no approval, and forcing thousands of them into the ISO 19650
 * register creates junk containers and buries the drawings the register exists for". And the exception: "there is a
 * *promote to register* action for the handful that become as-built evidence."
 *
 * Three decisions this class makes, each of which is the difference between the action being useful and it being the
 * bulk import §16.1 refused:
 *
 *  - **It puts the photograph *in* the register; it does not publish it.** The container is created at work-in-progress
 *    and moves through §15's gate like everything else, because publishing is `ConstructionDocumentPublish` and a
 *    different act by a different person. A promotion that published would be a way around the approval gate that §15
 *    says must never be bypassed.
 *  - **The register's own permission gates the action**, not the diary's. Whoever may write a diary is not
 *    automatically whoever may put a container in the register — and that distinction is the only thing standing
 *    between the register and the junk it was kept out of.
 *  - **One file, two rows.** The revision carries the same path, name, size, mime and sha256 as the photograph rather
 *    than a second copy of the bytes, so the register holds the *same* image an adjudicator was shown and not a
 *    re-encoding of it. The consequence is enforced in `DailyLogPhoto`: a promoted photograph cannot be deleted from
 *    the diary, because a register container pointing at a missing file is worse than no container.
 */
class SitePhotoPromotion
{
    public function __construct(private readonly DocumentStateMachine $states) {}

    /**
     * Promote one photograph, returning the register container it became.
     *
     * @param  array<string, mixed>  $attributes  Overrides for the container — a title, a suitability code, the
     *                                            `is_contractual` flag for evidence somebody will rely on.
     */
    public function promote(DailyLogPhoto $photo, array $attributes = []): Document
    {
        if ($photo->isPromoted()) {
            throw new InvalidArgumentException(
                "This photograph is already in the register as {$photo->promotedDocument?->information_container_id}. "
                .'Promoting it twice would put the same image in twice under two identifiers, which is the junk §16.1 '
                .'keeps photographs out of the register to avoid.'
            );
        }

        if (blank($photo->file_path)) {
            throw new InvalidArgumentException('There is no file to promote.');
        }

        $log = $photo->dailyLog;
        $job = $log?->job;

        if ($job === null) {
            throw new InvalidArgumentException('A photograph can only be promoted from a diary that names a job.');
        }

        return TenantTransaction::run(function () use ($photo, $log, $job, $attributes): Document {
            $convention = NamingConvention::query()
                ->where('job_id', $job->getKey())
                ->where('is_default', true)
                ->first();

            $document = new Document(array_merge([
                'title' => $photo->caption,
                // The provenance is part of the record: a register container whose origin is "somebody uploaded it"
                // proves less than one that says which diary, which day and which place.
                'description' => trim(
                    ($photo->description ? $photo->description."\n\n" : '')
                    .'Promoted from the site diary for '.$log->log_date->format('D d M Y')
                    .' ('.$photo->subjectLabel().')'
                    .($photo->location ? ' at '.$photo->location->fullName() : '')
                    .'.'
                ),
                'location_id' => $photo->location_id,
                'is_contractual' => false,
            ], $attributes));

            $document->job_id = $job->getKey();
            // Always a photograph, whatever the caller passed: the register's own type list has the value, and
            // mislabelling one as a drawing is how it ends up in a construction-issue filter.
            $document->document_type = 'photograph';
            // **Work in progress**, always. Publishing is §15's gate and a different permission.
            $document->cde_state = Document::STATE_WIP;

            $this->applyNaming($document, $job->code, $convention);

            $document->save();

            $this->states->issueRevision($document, [
                // A photograph has no revisions — it is one moment, recorded once. The register needs a revision row
                // to hold the file, so it gets the first one and never a second.
                'revision' => 'A',
                'reason_for_issue' => 'Promoted from the site diary',
                'issued_on' => ($photo->taken_at ?? $log->log_date)->toDateString(),
                'issued_by' => auth()->id(),
                'approval_status' => DocumentRevision::APPROVAL_NOT_REQUIRED,
                'file_path' => $photo->file_path,
                'file_name' => $photo->file_name,
                'file_size' => $photo->file_size,
                'file_mime' => $photo->file_mime,
                'file_hash' => $photo->file_hash,
            ]);

            $photo->update([
                'promoted_document_id' => $document->getKey(),
                'promoted_at' => now(),
                'promoted_by' => auth()->id(),
            ]);

            return $document->refresh();
        });
    }

    /**
     * Give the container an identifier the register can hold.
     *
     * `construction_documents` is unique on `(job_id, information_container_id)`, so this has to produce a free one —
     * and where the job has issued a naming convention it has to produce one *in that convention*, because a register
     * with two naming schemes in it is a register nobody can search.
     *
     * The photograph form code is `PH`. A project that has issued its own code list and does not include `PH` gets the
     * fallback rather than a refusal: the point of promoting evidence is that it ends up somewhere findable, and losing
     * that to a code-list mismatch would be the tail wagging the dog.
     */
    private function applyNaming(Document $document, ?string $jobCode, ?NamingConvention $convention): void
    {
        $prefix = $jobCode ?: 'JOB';
        $sequence = $this->nextSequence($document->job_id);

        if ($convention !== null && $convention->allows('form_code', 'PH')) {
            $document->naming_convention_id = $convention->getKey();
            $document->project_code = $prefix;
            $document->form_code = 'PH';
            $document->container_number = $sequence;

            $identifier = $document->assembleIdentifier($convention);

            if (! $this->isTaken($document->job_id, $identifier)) {
                $document->information_container_id = $identifier;

                return;
            }
        }

        $document->naming_convention_id = null;
        $document->form_code = 'PH';
        $document->container_number = $sequence;

        do {
            $identifier = "{$prefix}-PH-{$sequence}";
            $sequence = str_pad((string) ((int) $sequence + 1), 4, '0', STR_PAD_LEFT);
        } while ($this->isTaken($document->job_id, $identifier));

        $document->information_container_id = $identifier;
    }

    /** The next photograph number on the job, zero-padded so the register sorts. */
    private function nextSequence(int $jobId): string
    {
        $count = Document::query()
            ->where('job_id', $jobId)
            ->where('document_type', 'photograph')
            ->count();

        return str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    private function isTaken(int $jobId, string $identifier): bool
    {
        return Document::query()
            ->where('job_id', $jobId)
            ->where('information_container_id', $identifier)
            ->exists();
    }
}
