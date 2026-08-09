<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Bank;

/**
 * Chooses which account identifier goes into a bank payment file.
 *
 * The files are Standard Chartered iPayments (see config/ipayments.php). A
 * beneficiary who banks with SCB itself is an intra-bank transfer, keyed on the
 * plain account number; everyone else is an inter-bank IBFT transfer, keyed on
 * the IBAN. Sending the wrong one gets the payment rejected — or worse,
 * misdirected.
 *
 * SCB is deliberately absent from the IBFT bank directory (BankSeeder lists only
 * the banks you can transfer *out* to), so "is this our own bank?" is matched
 * from several angles: the bank's short code, its name, and the four-letter bank
 * identifier inside a Pakistani IBAN (characters 5-8, e.g. PK36**SCBL**0000…).
 */
class BankFileAccount
{
    /**
     * Nothing on file to pay into. The row would export with a blank beneficiary account,
     * which the bank rejects.
     */
    public const PROBLEM_NO_ACCOUNT = 'no_account';

    /**
     * An own-bank beneficiary with only an IBAN.
     *
     * A transfer within our own bank is keyed on the plain account number; the IBAN is the
     * identifier for going *out*. Sending one where the other is expected is the quiet
     * failure this exists to catch — the row looks complete, the file looks valid, and the
     * payment is rejected or misdirected days later.
     */
    public const PROBLEM_OWN_BANK_IBAN_ONLY = 'own_bank_iban_only';

    /** @var array<string, string> */
    public const PROBLEM_LABELS = [
        self::PROBLEM_NO_ACCOUNT => 'No bank account on file',
        self::PROBLEM_OWN_BANK_IBAN_ONLY => 'Only an IBAN, but they bank with us',
    ];

    /**
     * @return array{value: string, kind: string, is_own_bank: bool, problem: string|null}
     *                                                                                     kind is 'account_no', 'iban', or '' when neither is on file
     */
    public static function resolve(
        ?string $iban,
        ?string $accountNo,
        ?Bank $bank = null,
        ?string $bankShortCode = null,
        ?string $bankName = null,
    ): array {
        $iban = trim((string) $iban) ?: null;
        $accountNo = trim((string) $accountNo) ?: null;

        $ownBank = static::isOwnBank(
            $iban,
            $bankShortCode ?: $bank?->bank_short_code,
            $bankName ?: $bank?->bank_name,
        );

        // Preferred identifier first, then the other one: a blank field in a
        // payment file is never better than the wrong-shaped identifier, and the
        // export preview already flags rows with neither.
        [$first, $second] = $ownBank ? [$accountNo, $iban] : [$iban, $accountNo];
        [$firstKind, $secondKind] = $ownBank ? ['account_no', 'iban'] : ['iban', 'account_no'];

        if ($first !== null) {
            return ['value' => $first, 'kind' => $firstKind, 'is_own_bank' => $ownBank, 'problem' => null];
        }

        if ($second !== null) {
            // Still returned, so a caller that only wants an identifier gets the best one
            // available — but named as a problem, because for an own-bank beneficiary this
            // is the wrong kind and the payment will not go through on it.
            return [
                'value' => $second,
                'kind' => $secondKind,
                'is_own_bank' => $ownBank,
                'problem' => $ownBank ? self::PROBLEM_OWN_BANK_IBAN_ONLY : null,
            ];
        }

        return [
            'value' => '',
            'kind' => '',
            'is_own_bank' => $ownBank,
            'problem' => self::PROBLEM_NO_ACCOUNT,
        ];
    }

    public static function problemLabel(?string $problem): string
    {
        return self::PROBLEM_LABELS[$problem] ?? 'Bank details incomplete';
    }

    /** Just the identifier, for callers that do not care which kind it is. */
    public static function value(
        ?string $iban,
        ?string $accountNo,
        ?Bank $bank = null,
        ?string $bankShortCode = null,
        ?string $bankName = null,
    ): string {
        return static::resolve($iban, $accountNo, $bank, $bankShortCode, $bankName)['value'];
    }

    /** Whether this beneficiary banks with the same bank the file debits. */
    public static function isOwnBank(?string $iban, ?string $shortCode, ?string $bankName): bool
    {
        $config = config('ipayments.own_bank', []);

        $codes = array_map('strtoupper', $config['short_codes'] ?? []);

        if ($shortCode && in_array(strtoupper(trim($shortCode)), $codes, true)) {
            return true;
        }

        $needle = strtolower((string) ($config['name_contains'] ?? ''));

        if ($needle !== '' && $bankName && str_contains(strtolower($bankName), $needle)) {
            return true;
        }

        return static::ibanBelongsToOwnBank($iban, (string) ($config['iban_prefix'] ?? ''));
    }

    /**
     * A Pakistani IBAN is PK + 2 check digits + 4-letter bank identifier + the
     * account part, so the bank sits at offset 4.
     */
    protected static function ibanBelongsToOwnBank(?string $iban, string $prefix): bool
    {
        if ($prefix === '' || ! $iban) {
            return false;
        }

        $normalised = strtoupper(preg_replace('/\s+/', '', $iban));

        return str_starts_with(substr($normalised, 4), strtoupper($prefix));
    }
}
