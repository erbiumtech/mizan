<?php

namespace App\Modules\ConstructionContracts\Support;

use App\Modules\ConstructionContracts\Models\CertificateLine;
use Illuminate\Support\Collection;

/**
 * Server-side pagination for the printed continuation sheet — `docs/construction-management-plan.md` §10.5.
 *
 * **This class is the reason the printed certificate is the same document under both PDF engines.** The plan calls
 * the continuation sheet "the hardest page in this plan" — eleven columns, landscape, paginating over dozens of
 * pages — and the answer is to do the pagination in PHP rather than asking a rendering engine to do it. Four things
 * that buys, each of them a failure otherwise:
 *
 *  - `page-break-inside: avoid` on a row is unreliable, and chunking removes the dependency entirely.
 *  - `<thead>` repetition is version-sensitive and **fails silently**, so the header simply does not appear on page
 *    two of a forty-page sheet and nobody looks until the client does.
 *  - "Page 3 of 7" needs no engine feature when it is printed from the chunk index. Browsershot would need a header
 *    template and Dompdf a canvas callback, and `PdfDocument` exposes neither.
 *  - The output is the same document either way. "A certificate that paginates differently depending on whether the
 *    server happens to have Node installed is a certificate that cannot be reissued identically — and reissuing
 *    identically is the entire point of a certificate."
 *
 * It is not a workaround. It is what a printed bill of quantities has always looked like: a brought-forward row at
 * the top of each page and a carried-forward subtotal at the bottom.
 *
 * **Rows are counted, not lines**, and that distinction is the subtle part: a description over its character budget
 * prints a continuation row (§10.5, because Dompdf's `text-overflow` will not save you), so a line can occupy two
 * rows — and a line is never separated from its own continuation by a page break.
 */
final class CertificateSchedule
{
    /** Rows per printed page. §10.5's "roughly twenty-two rows a page", which a landscape A4 sheet holds. */
    public const ROWS_PER_PAGE = 22;

    /**
     * Characters of description a row prints before it spills onto a continuation row.
     *
     * A budget rather than CSS truncation: Dompdf has no usable `text-overflow`, and a description silently cut off
     * mid-word on the client's copy is worse than one that carries on to the next line.
     */
    public const DESCRIPTION_BUDGET = 68;

    /**
     * The column set for a standard — §8.4's G703 mapping, and its FIDIC equivalent.
     *
     * The AIA sheet is the eleven-column one and needs landscape; the FIDIC schedule is annexed to a portrait
     * summary and drops the columns that form is silent about. **Same rows, same chunking, different column set**,
     * which is the whole of what the standard drives (§8.3).
     *
     * @return array<int, array{key: string, label: string, head: string, align: string, width: int}>
     */
    public static function columnsFor(string $standard): array
    {
        $money = 'right';

        $common = [
            ['key' => 'item_no', 'label' => 'A', 'head' => 'Item', 'align' => 'left', 'width' => 8],
            ['key' => 'description', 'label' => 'B', 'head' => 'Description of work', 'align' => 'left', 'width' => 32],
            ['key' => 'scheduled_value', 'label' => 'C', 'head' => 'Scheduled value', 'align' => $money, 'width' => 12],
        ];

        if ($standard === ContractVocabulary::AIA) {
            return array_merge($common, [
                ['key' => 'previous_work_value', 'label' => 'D', 'head' => 'From previous applications', 'align' => $money, 'width' => 11],
                ['key' => 'work_this_period', 'label' => 'E', 'head' => 'This period', 'align' => $money, 'width' => 10],
                ['key' => 'cumulative_materials_value', 'label' => 'F', 'head' => 'Materials stored', 'align' => $money, 'width' => 9],
                ['key' => 'total_to_date', 'label' => 'G', 'head' => 'Total completed and stored', 'align' => $money, 'width' => 11],
                ['key' => 'percent_complete', 'label' => '%', 'head' => '%', 'align' => $money, 'width' => 5],
                ['key' => 'balance_to_finish', 'label' => 'H', 'head' => 'Balance to finish', 'align' => $money, 'width' => 10],
                ['key' => 'line_retention', 'label' => 'I', 'head' => 'Retainage', 'align' => $money, 'width' => 9],
            ]);
        }

        // FIDIC's annexed measurement schedule: the same figures, without the columns clause 14 is silent about.
        return array_merge($common, [
            ['key' => 'previous_work_value', 'label' => '', 'head' => 'Previously certified', 'align' => $money, 'width' => 14],
            ['key' => 'work_this_period', 'label' => '', 'head' => 'This period', 'align' => $money, 'width' => 12],
            ['key' => 'total_to_date', 'label' => '', 'head' => 'To date', 'align' => $money, 'width' => 12],
            ['key' => 'percent_complete', 'label' => '', 'head' => '%', 'align' => $money, 'width' => 6],
            ['key' => 'line_retention', 'label' => '', 'head' => 'Retention', 'align' => $money, 'width' => 12],
        ]);
    }

    /** The columns that carry money and therefore appear on the brought- and carried-forward rows. */
    public const SUBTOTAL_KEYS = [
        'scheduled_value', 'previous_work_value', 'work_this_period',
        'cumulative_materials_value', 'total_to_date', 'balance_to_finish', 'line_retention',
    ];

    /**
     * Chunk the lines into printed pages.
     *
     * @param  Collection<int, CertificateLine>|array<int, CertificateLine>  $lines
     * @return array<int, array{
     *     number: int,
     *     of: int,
     *     rows: array<int, array{line: CertificateLine, description: string, continuation: string|null}>,
     *     brought_forward: array<string, float>|null,
     *     carried_forward: array<string, float>,
     *     is_last: bool,
     * }>
     */
    public static function paginate($lines, int $rowsPerPage = self::ROWS_PER_PAGE): array
    {
        $rowsPerPage = max(1, $rowsPerPage);

        // Each line becomes one row, or two where the description spills. A line and its continuation are one
        // unit: splitting them would put half a description at the foot of a page and the rest overleaf.
        $units = [];

        foreach ($lines as $line) {
            [$description, $continuation] = self::split((string) $line->description);

            $units[] = [
                'row' => ['line' => $line, 'description' => $description, 'continuation' => $continuation],
                'height' => $continuation === null ? 1 : 2,
            ];
        }

        if ($units === []) {
            return [];
        }

        $pages = [];
        $current = [];
        $used = 0;

        foreach ($units as $unit) {
            if ($used > 0 && $used + $unit['height'] > $rowsPerPage) {
                $pages[] = $current;
                $current = [];
                $used = 0;
            }

            $current[] = $unit['row'];
            $used += $unit['height'];
        }

        if ($current !== []) {
            $pages[] = $current;
        }

        return self::withSubtotals($pages);
    }

    /**
     * Attach the brought-forward and carried-forward figures.
     *
     * Carried-forward is the running total *including* this page; brought-forward is the previous page's
     * carried-forward. The first page has **no** brought-forward row — nothing has been brought forward, and a row
     * of zeros there reads as a subtotal of something rather than as the start of the sheet.
     *
     * The last page's carried-forward is the grand total, and the view labels it as one: two rows saying the same
     * thing under different names is how a reader comes to believe there is an extra page.
     *
     * @param  array<int, array<int, array{line: CertificateLine, description: string, continuation: string|null}>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private static function withSubtotals(array $pages): array
    {
        $of = count($pages);
        $running = array_fill_keys(self::SUBTOTAL_KEYS, 0.0);
        $out = [];

        foreach ($pages as $index => $rows) {
            $brought = $index === 0 ? null : $running;

            foreach ($rows as $row) {
                foreach (self::SUBTOTAL_KEYS as $key) {
                    $running[$key] = round($running[$key] + self::value($row['line'], $key), 2);
                }
            }

            $out[] = [
                'number' => $index + 1,
                'of' => $of,
                'rows' => $rows,
                'brought_forward' => $brought,
                'carried_forward' => $running,
                'is_last' => $index + 1 === $of,
            ];
        }

        return $out;
    }

    /**
     * One cell's value, derived where §8.4 says it is derived.
     *
     * Columns E, G and H are computed from the two cumulative figures and the scheduled value rather than stored,
     * so the printed sheet cannot disagree with itself — which is the same reason the certificate stores cumulative
     * figures and derives the movement.
     */
    public static function value(CertificateLine $line, string $key): float
    {
        return match ($key) {
            'work_this_period' => $line->workThisPeriod(),
            'total_to_date' => $line->totalToDate(),
            'balance_to_finish' => $line->balanceToFinish(),
            'percent_complete' => (float) ($line->percentComplete() ?? 0),
            default => (float) ($line->{$key} ?? 0),
        };
    }

    /**
     * A description split into what prints on the row and what continues below it.
     *
     * Split on a word boundary where there is one within the budget: a break mid-word looks like a fault in the
     * document, and a client reading their own copy is the wrong person to have to work that out.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function split(string $description, int $budget = self::DESCRIPTION_BUDGET): array
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');

        if (mb_strlen($description) <= $budget) {
            return [$description, null];
        }

        $head = mb_substr($description, 0, $budget);
        $break = mb_strrpos($head, ' ');

        if ($break !== false && $break > (int) ($budget * 0.6)) {
            $head = mb_substr($head, 0, $break);
        }

        $rest = trim(mb_substr($description, mb_strlen($head)));

        // The continuation gets its own budget: a description of three hundred characters is a specification
        // clause somebody pasted in, and printing all of it would take the row count with it.
        return [$head, mb_strimwidth($rest, 0, $budget * 2, '…')];
    }
}
