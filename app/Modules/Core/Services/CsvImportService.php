<?php

namespace App\Modules\Core\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Getting a company's existing records in at setup.
 *
 * The GnuCash importer is the hard version of this — a whole book, with accounts and
 * history — and nobody setting up needs it. What they have is a spreadsheet of clients,
 * a spreadsheet of products, and a trial balance from their old system.
 *
 * Every import is checked before anything is written, and reports what it would do row
 * by row: an import that half-succeeds and stops leaves somebody guessing which half.
 * Rows that cannot be used are named with their line number and skipped, rather than
 * aborting the rest — a typo on line 40 should not cost the other 39.
 */
class CsvImportService
{
    public const TYPE_CONTACTS = 'contacts';

    public const TYPE_PRODUCTS = 'products';

    public const TYPE_OPENING_BALANCES = 'opening_balances';

    /** The columns each import expects, in order, and what they mean. */
    public const COLUMNS = [
        self::TYPE_CONTACTS => ['name', 'kind', 'email', 'phone', 'ntn', 'cnic', 'address'],
        self::TYPE_PRODUCTS => ['sku', 'name', 'unit', 'description'],
        self::TYPE_OPENING_BALANCES => ['account_code', 'debit', 'credit'],
    ];

    public const LABELS = [
        self::TYPE_CONTACTS => 'Clients and suppliers',
        self::TYPE_PRODUCTS => 'Products',
        self::TYPE_OPENING_BALANCES => 'Opening balances',
    ];

    /**
     * Read a CSV into rows keyed by the expected columns.
     *
     * The header is used to find the columns rather than assuming their order, because
     * a spreadsheet exported twice rarely has them in the same order. Extra columns are
     * ignored; a missing required one is an error about the file, not about a row.
     *
     * @return Collection<int, array<string, string>>
     */
    public function read(string $contents, string $type): Collection
    {
        $expected = self::COLUMNS[$type] ?? throw new InvalidArgumentException("Unknown import type {$type}.");

        $lines = preg_split('/\R/', trim($contents)) ?: [];

        if (count($lines) < 2) {
            throw new InvalidArgumentException('That file has a header and no rows.');
        }

        $header = array_map(
            fn (string $column): string => str_replace(' ', '_', strtolower(trim($column, " \t\"'"))),
            str_getcsv(array_shift($lines)),
        );

        $required = $expected[0];

        if (! in_array($required, $header, true)) {
            throw new InvalidArgumentException(
                "That file has no \"{$required}\" column. Expected: ".implode(', ', $expected).'.'
            );
        }

        $rows = collect();

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = ['_line' => $index + 2];

            foreach ($expected as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = $position === false ? '' : trim((string) ($values[$position] ?? ''));
            }

            $rows->push($row);
        }

        return $rows;
    }

    /**
     * What an import would do, without doing it.
     *
     * @return array{rows: array<int, array<string, mixed>>, ready: int, skipped: int}
     */
    public function preview(string $contents, string $type): array
    {
        $rows = $this->read($contents, $type)
            ->map(fn (array $row): array => $row + ['_problem' => $this->problemWith($row, $type)])
            ->all();

        return [
            'rows' => $rows,
            'ready' => count(array_filter($rows, fn (array $row): bool => $row['_problem'] === null)),
            'skipped' => count(array_filter($rows, fn (array $row): bool => $row['_problem'] !== null)),
        ];
    }

    /**
     * @return array{imported: int, skipped: array<int, string>}
     */
    public function import(string $contents, string $type, ?string $openingDate = null): array
    {
        $rows = $this->read($contents, $type);
        $skipped = [];
        $usable = collect();

        foreach ($rows as $row) {
            if ($problem = $this->problemWith($row, $type)) {
                $skipped[] = "Line {$row['_line']}: {$problem}";

                continue;
            }

            $usable->push($row);
        }

        $imported = match ($type) {
            self::TYPE_CONTACTS => $this->importContacts($usable),
            self::TYPE_PRODUCTS => $this->importProducts($usable),
            self::TYPE_OPENING_BALANCES => $this->importOpeningBalances($usable, $openingDate),
            default => 0,
        };

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** Why this row cannot be used, or null. */
    private function problemWith(array $row, string $type): ?string
    {
        return match ($type) {
            self::TYPE_CONTACTS => match (true) {
                $row['name'] === '' => 'no name',
                $row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL) => "\"{$row['email']}\" is not an email address",
                default => null,
            },
            self::TYPE_PRODUCTS => match (true) {
                $row['sku'] === '' => 'no SKU',
                $row['name'] === '' => 'no name',
                default => null,
            },
            self::TYPE_OPENING_BALANCES => $this->openingBalanceProblem($row),
            default => 'unknown import type',
        };
    }

    private function openingBalanceProblem(array $row): ?string
    {
        if ($row['account_code'] === '') {
            return 'no account code';
        }

        if (! Account::where('code', $row['account_code'])->exists()) {
            return "no account with code {$row['account_code']}";
        }

        $debit = (float) str_replace(',', '', $row['debit'] ?: '0');
        $credit = (float) str_replace(',', '', $row['credit'] ?: '0');

        if ($debit < 0 || $credit < 0) {
            return 'a negative amount — put it in the other column instead';
        }

        if (($debit > 0) === ($credit > 0)) {
            return 'an amount in both debit and credit, or in neither';
        }

        return null;
    }

    private function importContacts(Collection $rows): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            // By name, so running the same file twice corrects rather than duplicates.
            Contact::updateOrCreate(
                ['name' => $row['name']],
                [
                    'kind' => in_array($row['kind'], [Contact::KIND_CUSTOMER, Contact::KIND_SUPPLIER, Contact::KIND_BOTH], true)
                        ? $row['kind']
                        : Contact::KIND_CUSTOMER,
                    'email' => $row['email'] ?: null,
                    'phone' => $row['phone'] ?: null,
                    'ntn' => $row['ntn'] ?: null,
                    'cnic' => $row['cnic'] ?: null,
                    'address_line_1' => $row['address'] ?: null,
                    'is_active' => true,
                ],
            );

            $imported++;
        }

        return $imported;
    }

    private function importProducts(Collection $rows): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            Product::updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'name' => $row['name'],
                    'unit' => $row['unit'] ?: 'pcs',
                    'description' => $row['description'] ?: null,
                    'is_active' => true,
                ],
            );

            $imported++;
        }

        return $imported;
    }

    /**
     * Opening balances as one journal entry, balanced by Opening Balance Equity.
     *
     * One entry, not one per row, because a trial balance is a single fact about a
     * single date. Whatever the rows do not balance to lands in 3300, which is what
     * that account is for and what the trial balance and balance sheet both already
     * report on: a half-entered opening position shows up there rather than as an
     * imbalance nobody can see.
     */
    private function importOpeningBalances(Collection $rows, ?string $date): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }

        $date ??= now()->toDateString();
        $lines = [];
        $net = 0.0;

        foreach ($rows as $row) {
            $account = Account::where('code', $row['account_code'])->firstOrFail();
            $debit = round((float) str_replace(',', '', $row['debit'] ?: '0'), 2);
            $credit = round((float) str_replace(',', '', $row['credit'] ?: '0'), 2);

            $lines[] = $debit > 0
                ? ['account_id' => $account->id, 'debit_amount' => $debit, 'description' => 'Opening balance']
                : ['account_id' => $account->id, 'credit_amount' => $credit, 'description' => 'Opening balance'];

            $net = round($net + $debit - $credit, 2);
        }

        if (abs($net) >= 0.005) {
            $obe = Account::where('code', '3300')->firstOrFail();

            $lines[] = $net > 0
                ? ['account_id' => $obe->id, 'credit_amount' => $net, 'description' => 'Opening Balance Equity']
                : ['account_id' => $obe->id, 'debit_amount' => -$net, 'description' => 'Opening Balance Equity'];
        }

        $entries = app(JournalEntryService::class);

        $entry = $entries->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => 'Opening balances imported from CSV',
        ], $lines);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $entries->post($entry);

        return $rows->count();
    }

    /** A file somebody can fill in, rather than a format they have to guess. */
    public function template(string $type): string
    {
        $columns = self::COLUMNS[$type] ?? throw new InvalidArgumentException("Unknown import type {$type}.");

        $example = match ($type) {
            self::TYPE_CONTACTS => ['Erbium AG', 'customer', 'billing@erbium.example', '+41 44 000 0000', '', '', 'Zurich'],
            self::TYPE_PRODUCTS => ['SKU-001', 'Laptop stand', 'pcs', 'Aluminium, adjustable'],
            self::TYPE_OPENING_BALANCES => ['1100', '250000.00', ''],
            default => [],
        };

        return implode(',', $columns)."\n".implode(',', $example)."\n";
    }
}
