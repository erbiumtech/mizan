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

    /**
     * Commission / brokerage. Ordinary business income — slab-taxed, declared
     * under the business head — kept apart from BUSINESS only so the s.233
     * withholding it suffers is recognised and itemised rather than lost in the
     * general advance-tax account. Whether it needs any treatment beyond this is
     * §7 Q5 of docs/legal-entity-types-plan.md, for the advisor.
     */
    public const COMMISSION = 'commission';

    public const RENTAL = 'rental';

    public const CAPITAL_GAINS = 'capital_gains';

    public const EXPORT_SERVICES = 'export_services';

    /** @var array<string, string> */
    public const ALL = [
        self::SALARIED => 'Salaried',
        self::BUSINESS => 'Business / self-employed',
        self::COMMISSION => 'Commission / brokerage (s.233)',
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

    /**
     * Regimes taxed on another regime's schedule rather than one of their own.
     * Commission/brokerage is ordinary business income — slab-taxed on the
     * business (non-salaried/AOP) schedule — so no `commission` schedule is
     * seeded; its tax resolves to the business one. The single place that records
     * a borrower, read by the tax lookup and by the seeder-coverage test alike.
     *
     * @var array<string, string> borrower => the regime whose schedule it uses
     */
    public const SCHEDULE_ALIASES = [
        self::COMMISSION => self::BUSINESS,
    ];

    public static function label(?string $regime): ?string
    {
        return $regime === null ? null : (self::ALL[$regime] ?? $regime);
    }

    public static function isFinal(?string $regime): bool
    {
        return in_array($regime, self::FINAL, true);
    }

    /** The schedule a regime is assessed on — itself, unless it borrows another's. */
    public static function scheduleRegime(string $regime): string
    {
        return self::SCHEDULE_ALIASES[$regime] ?? $regime;
    }

    /**
     * The regimes that have a schedule seeded of their own — every regime that is
     * not a borrower. The seeder must cover each of these for every shipped year.
     *
     * @return array<int, string>
     */
    public static function scheduled(): array
    {
        return array_values(array_filter(
            array_keys(self::ALL),
            fn (string $regime): bool => ! isset(self::SCHEDULE_ALIASES[$regime]),
        ));
    }
}
