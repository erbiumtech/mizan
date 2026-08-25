<?php

namespace App\Modules\Accounting\Support;

use InvalidArgumentException;

/**
 * Turn a written amount into a number — `docs/ai-command-bot-plan.md` §3.1.
 *
 * Done here rather than in the prompt because it is arithmetic, and a model
 * asked to multiply by 100,000 will occasionally get it wrong in a way that
 * looks exactly like a correct answer. The model returns what it heard; this
 * decides what it is worth.
 *
 * **The non-ASCII digit guard is the load-bearing part.** `(float) '۲۵۰۰۰'` is
 * `0.0` in PHP — no notice, no error. Booked, that is a zero-amount entry; and
 * while `RegisterEntryService::bookRow()` does refuse `$amount <= 0`, the refusal
 * would name nothing a user could act on. Urdu-Indic digits are normalised, and
 * anything else non-ASCII is refused by name.
 */
class AmountWords
{
    /** Urdu-Indic ۰-۹ and Arabic-Indic ٠-٩, which look identical in most fonts. */
    private const DIGIT_MAP = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Multipliers, longest first so "lakh" is not matched inside a longer word
     * and so `crore` is tried before `cr`.
     */
    private const SCALES = [
        'crore' => 10000000,
        'karor' => 10000000,
        'kror' => 10000000,
        'کروڑ' => 10000000,
        'lakh' => 100000,
        'lac' => 100000,
        'lakhs' => 100000,
        'لاکھ' => 100000,
        'hazaar' => 1000,
        'hazar' => 1000,
        'thousand' => 1000,
        'ہزار' => 1000,
        'k' => 1000,
    ];

    /**
     * @throws InvalidArgumentException when the text carries no usable number
     */
    public static function parse(string $text): float
    {
        $original = trim($text);

        if ($original === '') {
            throw new InvalidArgumentException('No amount was given.');
        }

        $normalised = strtr($original, self::DIGIT_MAP);

        // After digit normalisation, anything left outside the ASCII range is a
        // numeral system this does not know. Refuse by name rather than let
        // `(float)` turn it into zero.
        if (preg_match('/[^\x00-\x7F]/u', preg_replace('/[\p{L}\s]/u', '', $normalised) ?? '')) {
            throw new InvalidArgumentException(
                "\"{$original}\" uses digits this does not recognise. Write the amount in ordinary digits."
            );
        }

        $lower = mb_strtolower($normalised);

        /*
         * Group separators are DELETED, not spaced.
         *
         * Replacing them with a space turns "25,000" into "25 000", and the number match below then takes
         * the first run of digits and returns 25 — a hundredth of the intended figure, booked without
         * complaint because 25 is a perfectly valid amount. Currency words are spaced (so "rs25000" does
         * not become "rs25000"), separators are removed.
         */
        $lower = str_replace([',', '٬'], '', $lower);   // thousands separators
        $lower = str_replace('٫', '.', $lower);          // Arabic decimal separator
        $lower = str_replace(['rs.', 'rs', 'pkr', '/-'], ' ', $lower);

        $multiplier = 1;

        foreach (self::SCALES as $word => $scale) {
            // `k` only as a suffix on a number (25k), never as a bare letter.
            $pattern = $word === 'k'
                ? '/(?<=\d)\s*k\b/u'
                : '/\b'.preg_quote($word, '/').'\b/u';

            if (preg_match($pattern, $lower)) {
                $multiplier = $scale;
                $lower = (string) preg_replace($pattern, ' ', $lower, 1);
                break;
            }
        }

        if (! preg_match('/-?\d+(?:\.\d+)?/', $lower, $m)) {
            throw new InvalidArgumentException("\"{$original}\" does not contain an amount.");
        }

        $amount = round(((float) $m[0]) * $multiplier, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "\"{$original}\" came out as ".number_format($amount, 2).'. An amount must be greater than zero.'
            );
        }

        return $amount;
    }
}
