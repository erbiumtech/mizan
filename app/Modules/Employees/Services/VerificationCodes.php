<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeVerification;
use App\Modules\Employees\Models\LetterTemplate;
use App\Support\Pdf\PdfDocument;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

/**
 * Issuing and checking the codes that prove a phone number or an address —
 * docs/saas-launch-plan.md §14.8.
 *
 * Six digits, hashed, with an expiry and a try count. Six digits is weak on its own
 * and is made strong by the two things around it: the code dies after
 * {@see EmployeeVerification::MAX_ATTEMPTS} wrong tries, and it is only ever sent to
 * the value being proved — somebody who could receive it already holds the thing the
 * check is about.
 *
 * The address code is the odd one: it goes on a letter (§14.7) that is posted, which
 * is exactly why it proves an address, and why it is good for a month rather than a
 * quarter of an hour.
 */
class VerificationCodes
{
    public function __construct(
        private readonly WhatsAppSender $whatsapp,
        private readonly TemplatedLetters $letters,
    ) {}

    /**
     * Start a check: a new code, stored hashed, sent to the value being proved.
     *
     * @return PdfDocument|null the letter to print, where the kind is sent on paper
     *
     * @throws InvalidArgumentException when the record has nothing to check
     * @throws WhatsAppException|RuntimeException when it could not be sent
     */
    public function issue(Employee $employee, string $kind): ?PdfDocument
    {
        $target = EmployeeVerification::valueOn($employee, $kind);

        if ($target === null) {
            throw new InvalidArgumentException(match ($kind) {
                EmployeeVerification::KIND_PHONE => 'There is no phone number on this record to check.',
                EmployeeVerification::KIND_EMERGENCY_PHONE => 'There is no emergency contact number on this record to check.',
                EmployeeVerification::KIND_ADDRESS => 'There is no current address on this record to check.',
                default => 'There is nothing on this record to check.',
            });
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $row = EmployeeVerification::updateOrCreate(
            ['employee_id' => $employee->getKey(), 'kind' => $kind],
            [
                'target' => $target,
                'code_hash' => Hash::make($code),
                'sent_at' => now(),
                'expires_at' => now()->addMinutes(EmployeeVerification::MINUTES[$kind] ?? 15),
                'attempts' => 0,
                // A fresh code un-verifies what the old one proved: until this one comes
                // back, nothing has been shown about the value as it stands now.
                'verified_at' => null,
            ],
        );

        return $kind === EmployeeVerification::KIND_ADDRESS
            ? $this->postLetter($employee, $code)
            : $this->sendWhatsApp($row, $code);
    }

    /**
     * Check a code somebody typed.
     *
     * Returns a reason when it is refused, null when it is accepted — a string the
     * screen shows the person, because "that code has expired" and "that is not the
     * code" are different things to do next.
     */
    public function check(Employee $employee, string $kind, string $typed): ?string
    {
        $row = EmployeeVerification::where('employee_id', $employee->getKey())->where('kind', $kind)->first();

        if ($row === null || $row->code_hash === null) {
            return 'Nothing has been sent for that yet.';
        }

        if ($row->target !== EmployeeVerification::valueOn($employee, $kind)) {
            return 'That has changed since the code was sent. Send a new one.';
        }

        if (! $row->isUsable()) {
            return $row->attempts >= EmployeeVerification::MAX_ATTEMPTS
                ? 'Too many wrong tries. Send a new code.'
                : 'That code has expired. Send a new one.';
        }

        if (! Hash::check(trim($typed), (string) $row->code_hash)) {
            $row->increment('attempts');

            return 'That is not the code.';
        }

        // The code is spent either way: a code that keeps working after it has worked
        // is a code worth intercepting twice.
        $row->update(['verified_at' => now(), 'code_hash' => null, 'attempts' => 0]);

        return null;
    }

    private function sendWhatsApp(EmployeeVerification $row, string $code): null
    {
        $this->whatsapp->sendText(
            (string) $row->target,
            "Your verification code is {$code}. It is good for "
            .(EmployeeVerification::MINUTES[$row->kind] ?? 15).' minutes. Nobody should ask you for it.',
        );

        return null;
    }

    /**
     * The address letter, for printing and posting.
     *
     * Not emailed, and that is the whole mechanism: a code that arrives at the address
     * is what proves the address. The letter is the company's own `address_verification`
     * template, so the wording is theirs.
     */
    private function postLetter(Employee $employee, string $code): PdfDocument
    {
        if (LetterTemplate::for(LetterTemplate::KIND_ADDRESS_VERIFICATION) === null) {
            throw new RuntimeException(
                'There is no active address verification letter. Write one under Settings → Letter Templates; '
                .'put {code} in it where the code should print.'
            );
        }

        return $this->letters->renderPdf($employee, LetterTemplate::KIND_ADDRESS_VERIFICATION, [
            'code' => $code,
            'address' => (string) $employee->postalAddress(),
        ]);
    }
}
