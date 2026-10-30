<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Support\BrandKit;
use App\Support\CompanyLetterhead;

/**
 * An email signature out of the record — docs/saas-launch-plan.md §14.10.
 *
 * **A table with inline styles, which is not a style choice.** Mail clients strip
 * stylesheets and most still lay out with tables; a signature built the way a web page
 * is built arrives as a column of unstyled text in Outlook, which is where most of
 * these will be pasted.
 *
 * Everything comes from the record. The point is not that it saves typing — it is
 * that forty people's signatures then say the same company name, spelt the same way,
 * with the same phone number, and change together when the company moves.
 *
 * No image. A logo in a signature is an attachment on every mail somebody sends, it
 * is blocked by default in half the clients that receive it, and it leaves a grey box
 * with a red cross where the brand was meant to be. The colour band does the same job
 * in nine bytes.
 */
class EmailSignature
{
    public function html(Employee $employee): string
    {
        $employee->loadMissing('user');

        $brand = BrandKit::forTemplate();
        $company = CompanyLetterhead::data();

        $rows = array_values(array_filter([
            $this->line('T', $employee->phone),
            $this->line('E', $employee->user?->email),
            $this->line('W', $company['website'] ?? null),
        ]));

        $name = e($employee->fullName());
        $title = e((string) ($employee->designation ?: ''));
        $legal = e((string) ($company['legal_name'] ?: config('app.name')));
        $address = e((string) ($company['address'] ?? ''));

        return trim(<<<HTML
        <table cellpadding="0" cellspacing="0" border="0" style="font-family:{$brand['font']};font-size:13px;color:#374151;line-height:1.45">
            <tr>
                <td style="border-left:3px solid {$brand['colour']};padding-left:12px">
                    <div style="font-size:15px;font-weight:bold;color:{$brand['accent']}">{$name}</div>
                    <div style="font-size:13px;color:#6b7280">{$title}</div>
                    <div style="font-size:13px;font-weight:bold;color:{$brand['accent']};margin-top:6px">{$legal}</div>
                    <div style="font-size:12px;color:#6b7280">{$address}</div>
                    <div style="font-size:12px;color:#6b7280;margin-top:6px">{$this->join($rows)}</div>
                </td>
            </tr>
        </table>
        HTML);
    }

    /** The same thing as text, for a client that refuses HTML. */
    public function text(Employee $employee): string
    {
        $employee->loadMissing('user');
        $company = CompanyLetterhead::data();

        return implode("\n", array_values(array_filter([
            $employee->fullName(),
            $employee->designation ?: null,
            $company['legal_name'] ?: config('app.name'),
            $company['address'] ?: null,
            trim(implode('  ', array_filter([
                $employee->phone ? 'T '.$employee->phone : null,
                $employee->user?->email ? 'E '.$employee->user->email : null,
                $company['website'] ?: null,
            ]))),
        ])));
    }

    private function line(string $label, ?string $value): ?string
    {
        return blank($value) ? null : '<span style="white-space:nowrap">'.$label.' '.e(trim($value)).'</span>';
    }

    /** @param  array<int, string>  $rows */
    private function join(array $rows): string
    {
        return implode('&nbsp;&nbsp;·&nbsp;&nbsp;', $rows);
    }
}
