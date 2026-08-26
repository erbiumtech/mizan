<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\TransactionType;

/**
 * What a spoken or typed command has to resolve to — `docs/ai-command-bot-plan.md` §3.
 *
 * Four slots and two flags. Everything the model is allowed to decide is in
 * here, which is a short list on purpose: direction, amount, which category,
 * which day. It decides no account, no ledger side, and no posting.
 */
class CommandGrammar
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /**
     * The words, from the request, mapped to a direction of money.
     *
     * Both halves are correct here without qualification, and the reason is a
     * constraint rather than a convention: `RegisterEntryService::registerAccounts()`
     * is filtered `type = asset`, so a register account is *always* an asset, and
     * by the standard rules a debit always increases it. Money in therefore always
     * debits the register account and money out always credits it — there is no
     * account type in this feature for which that inverts.
     *
     * Kept as data because the confirmation copy and the tests both read it, and
     * because a future locale adds rows rather than editing a prompt.
     *
     * @var array<string, string>
     */
    public const DIRECTION_WORDS = [
        'in' => self::DIRECTION_IN,
        'add' => self::DIRECTION_IN,
        'jama' => self::DIRECTION_IN,
        'debit' => self::DIRECTION_IN,
        'received' => self::DIRECTION_IN,
        'receive' => self::DIRECTION_IN,

        'out' => self::DIRECTION_OUT,
        'minus' => self::DIRECTION_OUT,
        'less' => self::DIRECTION_OUT,
        'credit' => self::DIRECTION_OUT,
        'paid' => self::DIRECTION_OUT,
        'spent' => self::DIRECTION_OUT,

        // Urdu, script and roman. Unambiguous in both directions — unlike جمع, which is why it sits in
        // the money-in list above on the colloquial reading and is never trusted on its own.
        'aaya' => self::DIRECTION_IN,
        'mila' => self::DIRECTION_IN,
        'wasool' => self::DIRECTION_IN,
        'آیا' => self::DIRECTION_IN,
        'ملا' => self::DIRECTION_IN,
        'وصول' => self::DIRECTION_IN,

        'gaya' => self::DIRECTION_OUT,
        'diya' => self::DIRECTION_OUT,
        'kharch' => self::DIRECTION_OUT,
        'ada' => self::DIRECTION_OUT,
        'گیا' => self::DIRECTION_OUT,
        'دیا' => self::DIRECTION_OUT,
        'خرچ' => self::DIRECTION_OUT,
    ];

    /**
     * The JSON schema the reply must satisfy.
     *
     * `transaction_type_code` is an enum built from this tenant's own rows, so the
     * model picks from what exists rather than inventing a category. `null` is a
     * permitted answer and means "ask" — never "file it under Other", because a
     * bot that quietly files things under Miscellaneous produces a ledger that
     * balances and tells nobody anything.
     *
     * @param  array<int, string>  $categoryCodes
     * @return array<string, mixed>
     */
    public static function schemaProperties(array $categoryCodes): array
    {
        return [
            'required' => ['direction', 'amount', 'transaction_type_code'],
            'properties' => [
                'direction' => [
                    // anyOf, not `type: ['string','null']` — structured outputs rejects a type ARRAY
                    // combined with an enum: "Enum value 'in' does not match declared type
                    // '['string','null']'". A nullable enum has to be spelled as a union of two schemas.
                    'anyOf' => [
                        ['type' => 'string', 'enum' => [self::DIRECTION_IN, self::DIRECTION_OUT]],
                        ['type' => 'null'],
                    ],
                    'description' => 'Direction of money from the account holder\'s own point of view. '
                        .'"in" when money was received; "out" when money was paid. Never a ledger side.',
                ],
                'amount' => [
                    'type' => ['string', 'null'],
                    'description' => 'The amount exactly as written or said, unparsed — "25,000", "25k", '
                        .'"1.5 lakh". Do not convert it to a number; the application does that.',
                ],
                'transaction_type_code' => [
                    'anyOf' => [
                        ['type' => 'string', 'enum' => $categoryCodes],
                        ['type' => 'null'],
                    ],
                    'description' => 'The category code this belongs to, chosen from the list in the system '
                        .'prompt. null when no category clearly matches — do not guess, and never pick a '
                        .'catch-all such as "other" merely because nothing else fits.',
                ],
                'date' => [
                    'type' => ['string', 'null'],
                    'description' => 'ISO 8601 date (YYYY-MM-DD) if one was stated or implied, else null '
                        .'for today. Resolve relative words against the date given in the user turn.',
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'description' => 'A short memo for the ledger, in the language the command was given in. '
                        .'Null to use the category name.',
                ],
            ],
        ];
    }

    /**
     * The fields every resolver's reply carries, whatever its slots are.
     *
     * Assembled by the router rather than repeated in each resolver: `confidence` and `ambiguity` are
     * facts about the model's own answer, not about any module's domain.
     *
     * @param  array<int, string>  $flaggable  slot names this resolver allows in `ambiguity`
     * @return array<string, mixed>
     */
    public static function envelopeProperties(array $flaggable): array
    {
        return [
            'confidence' => [
                'type' => 'number',
                'description' => '0 to 1. How confident you are in the whole interpretation.',
            ],
            'ambiguity' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => array_values($flaggable)],
                'description' => 'Slots you guessed at rather than read. Never include "direction": it '
                    .'follows from rules stated in the system prompt, so if you cannot determine it, '
                    .'return null for it instead of flagging it here.',
            ],
        ];
    }

    /** Slots a resolver permits in `ambiguity`. `direction` is filtered out even if the model sends it. */
    public static function flags(mixed $ambiguity, array $allowed = ['amount', 'transaction_type_code', 'date', 'description']): array
    {
        return array_values(array_intersect(is_array($ambiguity) ? $ambiguity : [], $allowed));
    }

    /** Illuminate\Support\Carbon throughout — the parent class fails a hint that wants the subclass. */
    public static function date(?string $iso): \Illuminate\Support\Carbon
    {
        if ($iso === null) {
            return \Illuminate\Support\Carbon::today();
        }

        try {
            return \Illuminate\Support\Carbon::parse($iso)->startOfDay();
        } catch (\Throwable) {
            return \Illuminate\Support\Carbon::today();
        }
    }

    /**
     * The stable half of the prompt: the rules and this tenant's categories.
     *
     * Everything in here is identical for every command this tenant sends, which
     * is what makes it cacheable. Nothing that changes between commands — the
     * utterance, today's date — belongs in this string.
     *
     * @param  \Illuminate\Support\Collection<int, TransactionType>  $categories
     */
    public static function systemPromptBody(string $lines): string
    {
        return <<<PROMPT
        You turn a short bookkeeping command into structured fields. You do not do accounting.
        Commands arrive in English, Urdu, or roman Urdu, often mixed in one sentence.

        DIRECTION. Report the direction money moved from the account holder's own point of view:
        "in" when they received money, "out" when they paid it.

        Money in: in, add, jama, debit, received, aaya, mila, wasool, آیا, ملا, وصول.
        Money out: out, minus, less, credit, paid, spent, gaya, diya, kharch, ada, گیا, دیا, خرچ.

        Two habits produce the wrong answer and you must not follow them. A bank statement says
        "credit" when money ARRIVES, because it is written from the bank's books — in this system
        "credit" means money out. In Urdu bookkeeping جمع (jama) heads the credit column, but
        colloquial "jama karna" means to deposit — here "jama" means money in. Follow the word list
        above, not either of those conventions. If the command carries no directional word at all and
        the direction cannot be read from the sentence, return null.

        CATEGORY. Choose exactly one code from this list, or null:

        {$lines}

        Match against the alternative spellings in brackets as readily as against the name itself.
        Return null rather than guessing. Do not fall back to a catch-all category.

        AMOUNT. Return it exactly as written, unparsed. The application understands "25k",
        "1.5 lakh", "2 crore", "۲۵ ہزار" and grouped digits; you do not need to convert anything,
        and you must not transliterate Urdu-Indic digits into Latin ones.

        DATE. Return ISO 8601 (YYYY-MM-DD), resolved against the date given in the user turn, or null
        for today. "aaj"/آج is today. "parson"/پرسوں is two days away — before today if the sentence is
        about something that happened, after it if it is about something planned.

        **"kal" (کل) means both yesterday and tomorrow in Urdu.** The difference is carried by verb
        tense, and a three-word command often has none. Read the tense where there is one. Where there
        is not, resolve it to YESTERDAY — a command about a transaction is nearly always about one that
        has already happened — and add "date" to the ambiguity array so the person is asked to look at
        it.

        Flag in "ambiguity" any slot you inferred rather than read. Never flag "direction".
        PROMPT;
    }
}
