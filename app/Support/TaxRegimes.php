<?php

namespace App\Support;

/**
 * The Pakistani income tax schedules an individual's income can fall under.
 *
 * Shared rather than owned by the Personal Finance module, because two modules
 * need it and neither should depend on the other: Personal Finance computes the
 * estimate, and Accounting has to offer the setting on an income account, since
 * that is where a personal account's chart of accounts lives.
 *
 * A list of names, deliberately not the rates. The brackets are seeded data in
 * tax_schedules, per tax year, so that a Finance Act is a re-seed rather than a
 * code change.
 */
final class TaxRegimes
{
    public const SALARIED = 'salaried';

    public const BUSINESS = 'business';

    public const RENTAL = 'rental';

    public const CAPITAL_GAINS = 'capital_gains';

    public const EXPORT_SERVICES = 'export_services';

    /** @var array<string, string> */
    public const ALL = [
        self::SALARIED => 'Salaried',
        self::BUSINESS => 'Business / self-employed',
        self::RENTAL => 'Rental / property income',
        self::CAPITAL_GAINS => 'Capital gains',
        self::EXPORT_SERVICES => 'Export of services (final, s.154A)',
    ];

    /**
     * Regimes whose tax is FINAL: charged on the gross as its own block, the way
     * an IRIS return prints them — the income shows under "Subject to Final Tax",
     * never joins taxable income (IRIS code 9100), and its tax lands in
     * Fixed / Final Tax (920100) instead of the slabs. Export of services under
     * s.154A and the flat capital-gains charge under s.37/37A are both this
     * shape; salary, business and rental are normal-regime and slab-taxed.
     *
     * @var array<int, string>
     */
    public const FINAL = [self::CAPITAL_GAINS, self::EXPORT_SERVICES];

    public static function label(?string $regime): ?string
    {
        return $regime === null ? null : (self::ALL[$regime] ?? $regime);
    }

    public static function isFinal(?string $regime): bool
    {
        return in_array($regime, self::FINAL, true);
    }
}
