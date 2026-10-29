<?php

namespace App\Support;

/**
 * What a company's documents look like, within limits — docs/saas-launch-plan.md §14.7.
 *
 * **A choice of layout and two lines of text, never free HTML**, and the limit is
 * the point. The payslip and the letters render under two engines — Chromium where
 * it is installed, Dompdf where it is not — and the per-engine override partials
 * exist because the second one supports a different subset of CSS. Markup typed by
 * a company would go straight past those overrides and produce a payslip that
 * renders on the developer's machine and not on the customer's server.
 *
 * So what is offered is: a line under the letterhead, a line in the footer bar, and
 * which blocks of the payslip print. Everything else about the sheet is the
 * application's, and stays consistent across the three letters and the payslip.
 */
final class DocumentLayout
{
    /** The payslip blocks a company may leave off, and what the settings screen calls each. */
    public const PAYSLIP_BLOCKS = [
        'bank' => 'Bank account and IBAN',
        'attendance' => 'Working days, leave and LOP',
        'signatures' => 'Signature lines',
    ];

    /** A line printed under the letterhead on every document. */
    public static function headerNote(): string
    {
        return trim((string) setting('documents.header_note'));
    }

    /**
     * A line printed in the footer bar.
     *
     * Where a company puts "This is a computer-generated document" or the wording its
     * auditor asks for.
     */
    public static function footerNote(): string
    {
        return trim((string) setting('documents.footer_note'));
    }

    /**
     * Whether one block of the payslip prints.
     *
     * Everything prints unless the company has said otherwise, so an install that has
     * never opened the screen gets the payslip it has always had.
     */
    public static function payslipShows(string $block): bool
    {
        $hidden = setting('documents.payslip_hidden', []);

        return ! in_array($block, is_array($hidden) ? $hidden : [], true);
    }
}
