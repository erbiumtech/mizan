<?php

namespace App\Modules\Employees\Listeners;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use App\Modules\Employees\Models\LetterTemplate;
use App\Modules\Employees\Services\TemplatedLetters;
use App\Notifications\DocumentIssued;
use Throwable;

/**
 * The letter that records the thing that just happened — docs/saas-launch-plan.md §14.7.
 *
 * An increment approved, a promotion or a transfer recorded, a probation
 * confirmed: each is a fact somebody will be asked to put in writing, and the
 * company has already written the words. Nothing is issued where there is no
 * template, because a letter the company did not word is not a letter it wants
 * sent in its name.
 */
class IssueEventLetter
{
    public function __construct(private readonly TemplatedLetters $letters) {}

    /** @param  array<string, string|null>  $values */
    public function issue(Employee $employee, string $kind, array $values = []): bool
    {
        if (! in_array($kind, LetterTemplate::EVENT_KINDS, true) || LetterTemplate::for($kind) === null) {
            return false;
        }

        $user = $employee->user;

        if ($user === null) {
            return false;
        }

        try {
            $pdf = $this->letters->renderPdf($employee, $kind, $values);
            $user->notify(new DocumentIssued(LetterTemplate::KINDS[$kind] ?? $kind, $pdf->getName(), $pdf->raw()));
        } catch (Throwable $e) {
            // A letter that cannot be rendered — no Chromium for an Urdu template, a
            // letterhead not filled in — must not undo the increment it was about.
            report($e);

            return false;
        }

        return true;
    }

    /** A dated job-history row — a transfer or a promotion — writes its own letter. */
    public function fromJobHistory(EmployeeJobHistory $row): bool
    {
        $kind = match ($row->kind) {
            EmployeeJobHistory::KIND_PROMOTION => 'promotion',
            EmployeeJobHistory::KIND_TRANSFER => 'transfer',
            default => null,
        };

        if ($kind === null || $row->employee === null) {
            return false;
        }

        return $this->issue($row->employee, $kind, [
            'effective_from' => $row->effective_from?->format('d F Y') ?? '',
            'new_value' => $kind === 'promotion' ? (string) $row->designation : trim((string) $row->department.' '.(string) ($row->location?->name ?? '')),
            'old_value' => $kind === 'promotion' ? (string) $row->employee->getOriginal('designation') : (string) $row->employee->getOriginal('department'),
        ]);
    }
}
