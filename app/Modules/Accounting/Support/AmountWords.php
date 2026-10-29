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

    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /**
     * The other direction: a number as the words a cheque carries — docs/saas-launch-plan.md §8.6.
     *
     * The lakh-and-crore idiom, because that is what a Pakistani bank reads:
     * 1,250,000 is "Twelve Lakh Fifty Thousand", not "One Million Two Hundred
     * and Fifty Thousand". Paise as "and NN Paisa", and "Only" to close the line
     * the way the printed leaf expects. parse() reads figures with a scale word,
     * not these words; the two are not inverses.
     */
    /** The same figure in Urdu — docs/saas-launch-plan.md §8.10. Digits stay Western; only the words change. */
    private const URDU_ONES = [
        '', 'ایک', 'دو', 'تین', 'چار', 'پانچ', 'چھ', 'سات', 'آٹھ', 'نو', 'دس',
        'گیارہ', 'بارہ', 'تیرہ', 'چودہ', 'پندرہ', 'سولہ', 'سترہ', 'اٹھارہ', 'انیس',
    ];

    private const URDU_TENS = ['', '', 'بیس', 'تیس', 'چالیس', 'پچاس', 'ساٹھ', 'ستر', 'اسی', 'نوے'];

    private const URDU_WORDS = [
        'crore' => 'کروڑ', 'lakh' => 'لاکھ', 'thousand' => 'ہزار', 'hundred' => 'سو',
        'rupees' => 'روپے', 'paisa' => 'پیسے', 'and' => 'اور', 'only' => 'صرف', 'zero' => 'صفر',
    ];

    public static function write(float $amount, string $currency = 'Rupees', ?string $locale = null): string
    {
        if (($locale ?? app()->getLocale()) === 'ur') {
            return self::writeUrdu($amount);
        }

        $amount = round(abs($amount), 2);
        $rupees = (int) floor($amount);
        $paisa = (int) round(($amount - $rupees) * 100);

        $words = $rupees === 0 ? 'Zero' : self::wholeToWords($rupees);
        $line = trim($currency.' '.$words);

        if ($paisa > 0) {
            $line .= ' and '.self::wholeToWords($paisa).' Paisa';
        }

        return $line.' Only';
    }

    /**
     * Urdu reads the amount first and "only" last, as the cheque leaf expects:
     * صرف بارہ لاکھ پچاس ہزار روپے. The lakh-and-crore idiom is the same.
     */
    private static function writeUrdu(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $rupees = (int) floor($amount);
        $paisa = (int) round(($amount - $rupees) * 100);

        $line = self::URDU_WORDS['only'].' '.($rupees === 0 ? self::URDU_WORDS['zero'] : self::urduWhole($rupees)).' '.self::URDU_WORDS['rupees'];

        if ($paisa > 0) {
            $line .= ' '.self::URDU_WORDS['and'].' '.self::urduWhole($paisa).' '.self::URDU_WORDS['paisa'];
        }

        return $line;
    }

    private static function urduWhole(int $n): string
    {
        $parts = [];

        foreach ([['crore', 10000000], ['lakh', 100000], ['thousand', 1000]] as [$name, $value]) {
            if ($n >= $value) {
                $parts[] = self::urduWhole(intdiv($n, $value)).' '.self::URDU_WORDS[$name];
                $n %= $value;
            }
        }

        if ($n >= 100) {
            $parts[] = self::URDU_ONES[intdiv($n, 100)].' '.self::URDU_WORDS['hundred'];
            $n %= 100;
        }

        if ($n >= 20) {
            $parts[] = self::URDU_TENS[intdiv($n, 10)].($n % 10 ? ' '.self::URDU_ONES[$n % 10] : '');
        } elseif ($n > 0) {
            $parts[] = self::URDU_ONES[$n];
        }

        return implode(' ', $parts);
    }

    private static function wholeToWords(int $n): string
    {
        $parts = [];

        foreach ([['Crore', 10000000], ['Lakh', 100000], ['Thousand', 1000]] as [$name, $value]) {
            if ($n >= $value) {
                $parts[] = self::wholeToWords(intdiv($n, $value)).' '.$name;
                $n %= $value;
            }
        }

        if ($n >= 100) {
            $parts[] = self::ONES[intdiv($n, 100)].' Hundred';
            $n %= 100;
        }

        if ($n >= 20) {
            $parts[] = self::TENS[intdiv($n, 10)].($n % 10 ? ' '.self::ONES[$n % 10] : '');
        } elseif ($n > 0) {
            $parts[] = self::ONES[$n];
        }

        return implode(' ', $parts);
    }

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
