<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\BankFileFormat;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Header, row and trailer templates over placeholders — docs/saas-launch-plan.md §14.1.
 *
 * A row is an associative array the two export services already build; the
 * renderer substitutes `{placeholder}` and `{placeholder:format}` and nothing
 * else — no expressions, no conditions. The list of placeholders is the
 * documentation, shown on the designer. The iPayments format is not rendered
 * here: it stays the writer it always was, chosen by kind.
 */
class BankFileRenderer
{
    /** @var array<string, string> placeholder => what it carries */
    public const ROW_PLACEHOLDERS = [
        'row_no' => 'Row number, from 1',
        'beneficiary_name' => 'Payee name',
        'beneficiary_account' => 'Account number or IBAN, as the bank file rule picks it',
        'beneficiary_iban' => 'IBAN, if any',
        'beneficiary_account_no' => 'Account number, if any',
        'beneficiary_bank_code' => 'Payee bank routing code',
        'beneficiary_bank_name' => 'Payee bank short code',
        'beneficiary_id' => 'CNIC or NTN',
        'beneficiary_email' => 'Payee email',
        'beneficiary_phone' => 'Payee phone',
        'address_1' => 'Address line 1',
        'address_2' => 'Address line 2',
        'amount' => 'Amount (two decimals; :0 for whole rupees, :cents for paisa with no point)',
        'details' => 'Payment details',
        'reference' => 'Customer reference',
        'value_date' => 'Value date (:Ymd, :d/m/Y, :dmY — any PHP date format)',
        'debit_account' => 'Your account number',
        'debit_bank_id' => 'Your bank id',
        'currency' => 'Currency code',
    ];

    /** @var array<string, string> */
    public const FILE_PLACEHOLDERS = [
        'count' => 'Number of rows',
        'total' => 'Sum of the amounts (formats as amount does)',
        'value_date' => 'Value date',
        'file_date' => 'Today',
        'debit_account' => 'Your account number',
        'debit_bank_id' => 'Your bank id',
        'currency' => 'Currency code',
        'company_name' => 'Your company name',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows  each keyed by ROW_PLACEHOLDERS
     * @param  array<string, mixed>  $file  keyed by FILE_PLACEHOLDERS, count and total filled here
     */
    public function render(BankFileFormat $format, array $rows, array $file = [], bool $preview = false): string
    {
        if (! $format->isTemplate()) {
            throw new InvalidArgumentException("{$format->name} is not a designed format.");
        }

        if (! $preview && $format->needsPreview()) {
            throw new InvalidArgumentException("{$format->name} has changed since it was last previewed. Open it, preview it against the real rows, then download.");
        }

        $total = 0.0;
        $lines = [];

        foreach (array_values($rows) as $i => $row) {
            $total += (float) ($row['amount'] ?? 0);
            $lines[] = $this->fill((string) $format->row_template, $row + ['row_no' => $i + 1] + $file);
        }

        $file += ['count' => count($rows), 'total' => round($total, 2), 'file_date' => now()->toDateString()];

        $out = [];

        if (trim((string) $format->header_template) !== '') {
            $out[] = $this->fill((string) $format->header_template, $file);
        }

        $out = array_merge($out, $lines);

        if (trim((string) $format->trailer_template) !== '') {
            $out[] = $this->fill((string) $format->trailer_template, $file);
        }

        return implode($format->lineEnding(), $out).$format->lineEnding();
    }

    /** `{name}` and `{name:format}`; an unknown placeholder renders empty rather than leaking its name into a bank file. */
    public function fill(string $template, array $values): string
    {
        return preg_replace_callback('/\{([a-z_]+)(?::([^}]+))?\}/', function (array $m) use ($values): string {
            $value = $values[$m[1]] ?? '';
            $modifier = $m[2] ?? null;

            if ($value instanceof \DateTimeInterface || (in_array($m[1], ['value_date', 'file_date'], true) && is_string($value) && $value !== '')) {
                $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);

                return $date->format($modifier ?: 'd/m/Y');
            }

            if (in_array($m[1], ['amount', 'total'], true)) {
                $amount = (float) $value;

                return match ($modifier) {
                    'cents' => (string) (int) round($amount * 100),
                    '0' => (string) (int) round($amount),
                    default => number_format($amount, (int) ($modifier ?: 2), '.', ''),
                };
            }

            $text = str_replace(["\r", "\n"], ' ', (string) $value);

            return $modifier !== null && ctype_digit($modifier) ? mb_substr($text, 0, (int) $modifier) : $text;
        }, $template) ?? '';
    }
}
