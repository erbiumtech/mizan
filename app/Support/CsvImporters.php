<?php

namespace App\Support;

use App\Support\Contracts\CsvImporter;
use InvalidArgumentException;

/**
 * What a company can import from a spreadsheet, contributed by the modules that own the records.
 *
 * `Core\Services\CsvImportService` wrote all three imports itself and so named five classes across
 * Accounting, Inventory and Invoicing. Core now reads CSV and this says what the file means — see
 * `App\Support\Contracts\CsvImporter` and docs/module-packaging-plan.md §9.
 *
 * **Registered as class names, resolved through the container on use.** Importers are constructor-injected
 * (opening balances needs `JournalEntryService`), and registration happens in a provider's `boot()` where
 * resolving a service is the wrong time to do it. Resolution is memoised, so the picker's labels cost one
 * instance per type.
 *
 * The list being registered rather than declared is the feature §9 pointed at: an unlicensed module's import
 * type is not merely hidden but absent, so nothing can name a type whose writer is not installed.
 */
class CsvImporters
{
    /** @var array<string, array{importer: class-string<CsvImporter>, sort: int}> */
    private static array $importers = [];

    /** @var array<string, CsvImporter> */
    private static array $resolved = [];

    /**
     * @param  class-string<CsvImporter>  $importer
     * @param  int  $sort  lower sorts first; the dropdown's order — and so which type a fresh page opens
     *                     on — is a decision, not the order the service providers happened to boot in
     */
    public static function register(string $key, string $importer, int $sort = 100): void
    {
        self::$importers[$key] = ['importer' => $importer, 'sort' => $sort];
        unset(self::$resolved[$key]);

        uasort(self::$importers, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
    }

    /** Whether anything can import this type — asked before a type from a form or a URL is trusted. */
    public static function has(string $key): bool
    {
        return isset(self::$importers[$key]);
    }

    public static function get(string $key): CsvImporter
    {
        if (! isset(self::$importers[$key])) {
            throw new InvalidArgumentException("Unknown import type {$key}.");
        }

        return self::$resolved[$key] ??= app(self::$importers[$key]['importer']);
    }

    /**
     * Every registered importer, keyed by type, in sort order.
     *
     * @return array<string, CsvImporter>
     */
    public static function all(): array
    {
        return array_combine(
            array_keys(self::$importers),
            array_map(fn (string $key): CsvImporter => self::get($key), array_keys(self::$importers)),
        );
    }

    /**
     * Type => label, for the "what are you importing?" select.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn (CsvImporter $importer): string => $importer->label(), self::all());
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::$importers);
    }

    public static function flush(): void
    {
        self::$importers = [];
        self::$resolved = [];
    }
}
