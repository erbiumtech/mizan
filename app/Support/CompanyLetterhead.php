<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The company as it appears at the top of a letter, and who signs at the bottom.
 *
 * **Extracted when the second letter arrived, not before.** The income certificate carried all of this
 * inline; the experience letter needs exactly the same letterhead, the same reference scheme and the same
 * "refuse while the registered details are blank" rule. Two copies of that would drift the first time
 * somebody added a field to one of them — and the drift would show up on company paper in front of a bank.
 *
 * Reads settings only, so it stays in `App\Support` without importing a module: the values are per company
 * through `TenantSettings`, defaulting to `config/company.php`.
 */
final class CompanyLetterhead
{
    /**
     * The fields no company letter can be issued without, in the reader's words.
     *
     * The name and the address are what make it *this* company's letter; the NTN is what a bank or an
     * embassy checks the company against; the signatory is who stands behind it. Phone, email, website and
     * incorporation number print when filled in and are left out when not — a letterhead missing its
     * website is still a letterhead.
     *
     * @var array<string, string>
     */
    public const REQUIRED = [
        'legal_name' => 'the company\'s registered name',
        'address' => 'the registered office address',
        'ntn' => 'the company NTN',
        'signatory_name' => 'the name of whoever signs',
    ];

    /** @return array<string, string> */
    public static function data(): array
    {
        return [
            'legal_name' => (string) setting('company.legal_name'),
            'address' => (string) setting('company.address'),
            'phone' => (string) setting('company.phone'),
            'email' => (string) setting('company.email'),
            'website' => (string) setting('company.website'),
            'registration_no' => (string) setting('company.registration_no'),
            'ntn' => (string) setting('company.ntn'),
        ];
    }

    /**
     * Who signs, overridable for one letter.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function signatory(array $input = []): array
    {
        return [
            'name' => (string) ($input['signatory_name'] ?? setting('company.signatory_name')),
            'title' => (string) ($input['signatory_title'] ?? setting('company.signatory_title') ?: 'Chief Executive Officer'),
            'email' => (string) setting('company.email'),
            'phone' => (string) setting('company.phone'),
        ];
    }

    /**
     * What is still blank, named as the settings screen names it.
     *
     * @return array<int, string>
     */
    public static function missing(): array
    {
        $missing = [];

        foreach (self::REQUIRED as $key => $label) {
            if (blank(setting("company.{$key}"))) {
                $missing[] = $label.' (Company Settings → Letterhead)';
            }
        }

        return $missing;
    }

    /**
     * A letter's reference — `ERB/HR/2026/EMP-014` — and it is deterministic on purpose.
     *
     * A running serial was the obvious alternative and it needs a counter somewhere: a counter written on a
     * *download* is a write on a read, two people downloading at once get the same number anyway, and a
     * re-issue would carry a reference the recipient has never seen. This way asking twice gives the same
     * answer, which is what a reference is for.
     *
     * @param  string  $subject  what the letter is about — an employee code, an invoice number
     */
    public static function reference(string $subject, ?Carbon $on = null): string
    {
        $prefix = (string) (setting('company.document_ref_prefix') ?: 'HR');
        $company = str((string) setting('company.legal_name'))->squish()->substr(0, 3)->upper()->value();

        return implode('/', array_filter([
            $company !== '' ? $company : 'DOC',
            $prefix,
            ($on ?? Carbon::today())->year,
            $subject,
        ]));
    }
}
