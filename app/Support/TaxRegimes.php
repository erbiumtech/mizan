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

    /** @var array<string, string> */
    public const ALL = [
        self::SALARIED => 'Salaried',
        self::BUSINESS => 'Business / self-employed',
        self::RENTAL => 'Rental / property income',
        self::CAPITAL_GAINS => 'Capital gains',
    ];

    public static function label(?string $regime): ?string
    {
        return $regime === null ? null : (self::ALL[$regime] ?? $regime);
    }
}
