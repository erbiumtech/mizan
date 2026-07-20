<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports GnuCash CSV exports (File → Export): Account Tree, Transactions,
 * and Active Register. See docs/accounting-implementation-plan.md Phase 17.
 */
class GnuCashImportService
{
    public const KIND_ACCOUNT_TREE = 'account_tree';
    public const KIND_TRANSACTIONS = 'transactions';
    public const KIND_REGISTER = 'register';

    protected const TYPE_MAP = [
        'ASSET' => 'asset', 'BANK' => 'asset', 'CASH' => 'asset', 'RECEIVABLE' => 'asset',
        'STOCK' => 'asset', 'MUTUAL' => 'asset',
        'LIABILITY' => 'liability', 'CREDIT' => 'liability', 'PAYABLE' => 'liability',
        'INCOME' => 'income',
        'EXPENSE' => 'expense',
        'EQUITY' => 'equity', 'ROOT' => 'equity', 'TRADING' => 'equity',
    ];

    protected const CODE_RANGES = [
        'asset' => [1000, 1999], 'liability' => [2000, 2999], 'equity' => [3000, 3999],
        'income' => [4000, 4999], 'expense' => [5000, 5999],
    ];

    public function __construct(
        private JournalEntryService $journalEntryService,
        private RegisterEntryService $registerEntryService,
    ) {
    }

    /**
     * Parse CSV text into header-keyed rows (BOM-safe, case-insensitive keys).
     */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));

        if (count($lines) < 2) {
            throw new InvalidArgumentException('CSV has no data rows.');
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        $rows = [];

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, ',', '"', '\\');

            if (count($values) < count($header)) {
                $values = array_pad($values, count($header), '');
            }

            $rows[] = ['_line' => $i + 2] + array_combine($header, array_slice($values, 0, count($header)));
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Which GnuCash export this is, from the header row.
     */
    public function detectKind(array $header): string
    {
        $has = fn (string $col) => in_array(strtolower($col), $header, true);

        if ($has('transaction id')) {
            return self::KIND_TRANSACTIONS;
        }

        if ($has('full account name') && $has('type')) {
            return self::KIND_ACCOUNT_TREE;
        }

        if ($has('transfer')) {
            return self::KIND_REGISTER;
        }

        throw new InvalidArgumentException('Unrecognized GnuCash CSV format — expected an Account Tree, Transactions, or Active Register export.');
    }

    /**
     * Import dispatcher (all writes in one DB transaction).
     * Register imports need $targetAccount.
     */
    public function import(string $csv, ?Account $targetAccount = null): array
    {
        $parsed = $this->parse($csv);
        $kind = $this->detectKind($parsed['header']);

        return DB::transaction(fn () => match ($kind) {
            self::KIND_ACCOUNT_TREE => $this->importAccountTree($parsed['rows']),
            self::KIND_TRANSACTIONS => $this->importTransactions($parsed['rows']),
            self::KIND_REGISTER => $this->importActiveRegister($parsed['rows'], $targetAccount),
        }) + ['kind' => $kind];
    }

    /**
     * Dry run: execute inside a rolled-back transaction, return the preview.
     */
    public function preview(string $csv, ?Account $targetAccount = null): array
    {
        $parsed = $this->parse($csv);
        $kind = $this->detectKind($parsed['header']);
        $result = null;

        try {
            DB::transaction(function () use ($parsed, $kind, $targetAccount, &$result) {
                $result = match ($kind) {
                    self::KIND_ACCOUNT_TREE => $this->importAccountTree($parsed['rows']),
                    self::KIND_TRANSACTIONS => $this->importTransactions($parsed['rows']),
                    self::KIND_REGISTER => $this->importActiveRegister($parsed['rows'], $targetAccount),
                };

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            // expected — everything rolled back
        }

        return $result + ['kind' => $kind, 'dry_run' => true];
    }

    // ── Account tree ────────────────────────────────────────────────

    protected function importAccountTree(array $rows): array
    {
        $created = 0;
        $matched = 0;
        $errors = [];

        foreach ($rows as $row) {
            $fullName = trim($row['full account name'] ?? '');
            $gnuType = strtoupper(trim($row['type'] ?? ''));

            if ($fullName === '' || $gnuType === 'ROOT') {
                continue;
            }

            $type = self::TYPE_MAP[$gnuType] ?? null;

            if (! $type) {
                $errors[] = "Line {$row['_line']}: unknown GnuCash type '{$gnuType}'";

                continue;
            }

            $placeholder = strtoupper(trim($row['placeholder'] ?? '')) === 'T';

            $account = $this->resolveByPath($fullName, $type, [
                'code' => trim($row['code'] ?? '') ?: null,
                'description' => trim($row['description'] ?? '') ?: null,
                'allow_manual_entry' => ! $placeholder,
                'is_active' => strtoupper(trim($row['hidden'] ?? '')) !== 'T',
            ], $wasCreated);

            $wasCreated ? $created++ : $matched++;
        }

        return ['accounts_created' => $created, 'accounts_matched' => $matched, 'errors' => $errors];
    }

    // ── Transactions ────────────────────────────────────────────────

    protected function importTransactions(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $id = trim($row['transaction id'] ?? '');

            if ($id === '') {
                continue;
            }

            $groups[$id][] = $row;
        }

        $created = 0;
        $skipped = 0;
        $accountsCreated = 0;
        $errors = [];

        foreach ($groups as $gnucashId => $group) {
            if (JournalEntry::where('gnucash_id', $gnucashId)->exists()) {
                $skipped++;

                continue;
            }

            $first = $group[0];
            $currency = strtoupper(trim($first['commodity/currency'] ?? ''));

            if ($currency && ! str_contains($currency, 'PKR') && ! str_contains($currency, 'CURRENCY')) {
                // GnuCash writes e.g. CURRENCY::PKR — reject anything else
                $errors[] = "Line {$first['_line']}: unsupported currency '{$currency}' (single-currency PKR engine)";

                continue;
            }

            $lines = [];
            $sum = 0.0;

            foreach ($group as $row) {
                $amount = $this->parseAmount($row['amount num.'] ?? $row['amount num'] ?? '0');

                if (abs($amount) < 0.005) {
                    continue;
                }

                $account = $this->resolveByPath(
                    trim($row['full account name'] ?? ''),
                    null,
                    [],
                    $wasCreated
                );

                if ($wasCreated) {
                    $accountsCreated++;
                }

                $sum += $amount;
                $lines[] = $amount > 0
                    ? ['account_id' => $account->id, 'debit_amount' => $amount, 'description' => trim($row['memo'] ?? '') ?: null]
                    : ['account_id' => $account->id, 'credit_amount' => -$amount, 'description' => trim($row['memo'] ?? '') ?: null];
            }

            if (abs($sum) > 0.01) {
                $errors[] = "Line {$first['_line']}: transaction '{$gnucashId}' does not balance (off by ".round($sum, 2).')';

                continue;
            }

            if (count($lines) < 2) {
                $errors[] = "Line {$first['_line']}: transaction '{$gnucashId}' has fewer than 2 non-zero splits";

                continue;
            }

            $entry = $this->journalEntryService->create([
                'entry_date' => $this->parseDate($first['date'] ?? '')->toDateString(),
                'entry_type' => 'general',
                'memo' => trim($first['description'] ?? '') ?: 'GnuCash import',
                'reference' => trim($first['number'] ?? '') ?: null,
                'gnucash_id' => $gnucashId,
            ], $lines);

            $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
            $this->journalEntryService->post($entry);

            $created++;
        }

        return [
            'entries_created' => $created,
            'duplicates_skipped' => $skipped,
            'accounts_created' => $accountsCreated,
            'errors' => $errors,
        ];
    }

    // ── Active register ─────────────────────────────────────────────

    protected function importActiveRegister(array $rows, ?Account $target): array
    {
        if (! $target) {
            throw new InvalidArgumentException('Register imports need a target account.');
        }

        $created = 0;
        $accountsCreated = 0;
        $errors = [];

        foreach ($rows as $row) {
            $transfer = trim($row['transfer'] ?? '');
            $debit = $this->parseAmount($row['debit'] ?? '0');
            $credit = $this->parseAmount($row['credit'] ?? '0');

            if ($transfer === '' || ($debit <= 0 && $credit <= 0)) {
                continue; // c/d, totals, or blank rows
            }

            try {
                $transferAccount = $this->resolveByPath($transfer, null, [], $wasCreated);

                if ($wasCreated) {
                    $accountsCreated++;
                }

                $entry = $this->registerEntryService->bookRow($target, $transferAccount, [
                    'date' => $this->parseDate($row['date'] ?? '')->toDateString(),
                    'description' => trim($row['description'] ?? '') ?: 'GnuCash register import',
                    'num' => trim($row['num'] ?? '') ?: null,
                    'direction' => $debit > 0 ? 'in' : 'out',
                    'amount' => $debit > 0 ? $debit : $credit,
                ]);

                if (strtolower(trim($row['r'] ?? '')) === 'y') {
                    $entry->lines()->where('account_id', $target->id)->update(['reconciled_at' => now()]);
                }

                $created++;
            } catch (InvalidArgumentException $e) {
                $errors[] = "Line {$row['_line']}: ".$e->getMessage();
            }
        }

        return ['entries_created' => $created, 'accounts_created' => $accountsCreated, 'errors' => $errors];
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Resolve (or create) an account from a colon-separated GnuCash path
     * like "Expenses:Utilities:Electric". Parents become non-postable
     * placeholders; the leaf gets $attributes.
     */
    protected function resolveByPath(string $fullName, ?string $type, array $attributes, ?bool &$wasCreated = null): Account
    {
        $wasCreated = false;
        $segments = array_values(array_filter(array_map('trim', explode(':', $fullName))));

        if (! $segments) {
            throw new InvalidArgumentException("Empty account path '{$fullName}'");
        }

        // Infer the type from the root segment when not given (Expenses:… → expense).
        $type = $type ?? match (strtolower($segments[0])) {
            'assets', 'asset' => 'asset',
            'liabilities', 'liability' => 'liability',
            'income' => 'income',
            'expenses', 'expense' => 'expense',
            'equity' => 'equity',
            default => 'expense',
        };

        $parent = null;

        foreach ($segments as $i => $name) {
            $isLeaf = $i === count($segments) - 1;

            $account = Account::where('name', $name)
                ->when($parent, fn ($q) => $q->where('parent_id', $parent->id), fn ($q) => $q->whereNull('parent_id'))
                ->first()
                // Root segments often mirror our existing top-level accounts by type.
                ?? ($parent === null ? Account::where('type', $type)->whereNull('parent_id')->where('name', 'like', $name.'%')->first() : null);

            if (! $account) {
                $account = Account::create([
                    'name' => $name,
                    'code' => ($isLeaf ? $attributes['code'] ?? null : null) ?? $this->nextCode($type),
                    'type' => $type,
                    'parent_id' => $parent?->id,
                    'allow_manual_entry' => $isLeaf ? ($attributes['allow_manual_entry'] ?? true) : false,
                    'description' => $isLeaf ? ($attributes['description'] ?? null) : null,
                    'is_active' => $attributes['is_active'] ?? true,
                ]);

                if ($isLeaf) {
                    $wasCreated = true;
                }
            }

            $parent = $account;
        }

        return $parent;
    }

    protected function nextCode(string $type): string
    {
        [$min, $max] = self::CODE_RANGES[$type] ?? [9000, 9999];

        $existing = Account::whereBetween(DB::raw('CAST(code AS UNSIGNED)'), [$min, $max])
            ->max(DB::raw('CAST(code AS UNSIGNED)'));

        $next = $existing ? $existing + 10 : $min + 100;

        while ($next > $max || Account::where('code', (string) $next)->exists()) {
            $next = $next > $max ? $existing + 1 : $next + 1;

            if ($next > $max) {
                throw new InvalidArgumentException("No free account codes left in the {$type} range.");
            }
        }

        return (string) $next;
    }

    protected function parseAmount(string $value): float
    {
        $clean = preg_replace('/[^\d.\-]/', '', str_replace(',', '', trim($value)));

        return $clean === '' || $clean === '-' ? 0.0 : (float) $clean;
    }

    protected function parseDate(string $value): Carbon
    {
        $value = trim($value);

        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        throw new InvalidArgumentException("Unparseable date '{$value}'");
    }
}

class DryRunRollback extends \RuntimeException
{
}
