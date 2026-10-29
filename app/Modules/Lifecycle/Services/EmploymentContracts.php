<?php

namespace App\Modules\Lifecycle\Services;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Models\EmploymentContract;
use App\Support\CompanyLetterhead;
use App\Support\DocumentVerification;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use InvalidArgumentException;

/** A contract's life — docs/saas-launch-plan.md §14.5: drafted, activated, accepted, signed, ended. */
class EmploymentContracts
{
    public const KIND = 'employment-contract';

    public function activate(EmploymentContract $contract): EmploymentContract
    {
        if ($contract->status !== EmploymentContract::STATUS_DRAFT) {
            throw new InvalidArgumentException("This contract is {$contract->status}, not a draft.");
        }

        // One active contract per person: the previous one ends the day before this starts.
        EmploymentContract::query()->active()->where('employee_id', $contract->employee_id)->whereKeyNot($contract->getKey())
            ->each(fn (EmploymentContract $old) => $old->update(['status' => EmploymentContract::STATUS_ENDED, 'ends_on' => $old->ends_on ?? $contract->starts_on->copy()->subDay()->toDateString()]));

        $contract->update(['status' => EmploymentContract::STATUS_ACTIVE]);

        return $contract->refresh();
    }

    /** The employee's own acceptance, in the panel — time and address, as a payslip acknowledgement is. */
    public function accept(EmploymentContract $contract, User $by, ?string $ip, array $witnesses = [], bool $ipDisclosure = false): EmploymentContract
    {
        if (! $contract->isActive()) {
            throw new InvalidArgumentException('Only an active contract can be accepted.');
        }

        if ((int) $contract->employee?->user_id !== (int) $by->getKey()) {
            throw new InvalidArgumentException('A contract is accepted by the person it names, nobody else.');
        }

        if ($contract->isAccepted()) {
            throw new InvalidArgumentException('This contract was already accepted on '.$contract->accepted_at->toDayDateTimeString().'.');
        }

        $contract->update([
            'accepted_at' => now(),
            'accepted_ip' => $ip,
            'witnesses' => array_values(array_filter(array_map('trim', $witnesses))),
            'ip_disclosure' => $ipDisclosure,
        ]);

        return $contract->refresh();
    }

    /** The signed scan, kept as an employee document so the expiring report reads it. */
    public function attachSignedScan(EmploymentContract $contract, string $path): EmployeeDocument
    {
        $document = EmployeeDocument::create([
            'employee_id' => $contract->employee_id,
            'kind' => 'contract',
            'number' => $contract->number,
            'issued_on' => $contract->starts_on->toDateString(),
            'expires_on' => $contract->ends_on?->toDateString(),
            'file_path' => $path,
        ]);

        $contract->update(['signed_document_id' => $document->getKey()]);

        return $document;
    }

    public function end(EmploymentContract $contract, ?string $on = null): EmploymentContract
    {
        $contract->update(['status' => EmploymentContract::STATUS_ENDED, 'ends_on' => $on ?? $contract->ends_on?->toDateString() ?? now()->toDateString()]);

        return $contract->refresh();
    }

    /** @return array<string, mixed> what the QR attests */
    public function verificationFacts(EmploymentContract $contract): array
    {
        return [
            'number' => $contract->number,
            'employee' => (string) $contract->employee?->fullName(),
            'type' => $contract->typeLabel(),
            'starts_on' => $contract->starts_on->toDateString(),
            'ends_on' => $contract->ends_on?->toDateString() ?? 'open-ended',
            'gross_monthly_salary' => (float) $contract->gross,
            'status' => $contract->status,
        ];
    }

    public function renderPdf(EmploymentContract $contract): PdfDocument
    {
        $contract->loadMissing('employee.user');

        return Pdf::view('pdfs.employment-contract', [
            'contract' => $contract,
            'employee' => $contract->employee,
            'company' => CompanyLetterhead::data(),
            'signatory' => CompanyLetterhead::signatory(),
            'verification_qr' => DocumentVerification::qrFor(self::KIND, $contract->getKey(), $this->verificationFacts($contract)),
        ])->format('a4')->name('contract-'.$contract->number.'.pdf');
    }
}
