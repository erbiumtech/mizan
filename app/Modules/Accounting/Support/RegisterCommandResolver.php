<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\RegisterEntryService;
use App\Support\TenantTransaction;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Money in and out of a cash or bank account — `docs/ai-command-bot-plan.md` §1, §2.
 *
 * The original resolver and the default one: an utterance no other resolver claims lands here, because a
 * bare "rent 25000 out" is a cash movement and always was.
 *
 * Everything §2 argues lives in this class now rather than in the router. The sign rules in particular:
 * a register account is always an asset, so money in debits it and money out credits it, and the
 * counter-leg follows from "debits equal credits" without anybody classifying the account.
 */
class RegisterCommandResolver implements CommandResolver
{
    public function __construct(private readonly RegisterEntryService $register) {}

    public function key(): string
    {
        return 'cash';
    }

    public function label(): string
    {
        return 'Cash / bank movement';
    }

    /** Gated on the module and on being allowed to create the entry it would post. */
    public function isAvailable(): bool
    {
        return modules()->enabled('accounting')
            && auth()->user()?->can('create', JournalEntry::class) !== false;
    }

    /** Claims nothing explicitly — it is where everything unclaimed goes. */
    public function claims(): array
    {
        return [];
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function promptSection(): string
    {
        $lines = $this->categories()
            ->map(function (TransactionType $t): string {
                $line = sprintf('- %s: %s', $t->code, $t->name);

                $aliases = $t->relationLoaded('aliases') ? $t->aliases->pluck('alias')->all() : [];

                return $aliases === [] ? $line : $line.' (also: '.implode(', ', $aliases).')';
            })
            ->implode("\n");

        return CommandGrammar::systemPromptBody($lines);
    }

    public function schemaProperties(): array
    {
        return CommandGrammar::schemaProperties($this->categories()->pluck('code')->all());
    }

    public function resolve(CommandUtterance $row, array $parsed): CommandInterpretation
    {
        $categories = $this->categories();
        $questions = [];

        $direction = in_array($parsed['direction'] ?? null, [CommandGrammar::DIRECTION_IN, CommandGrammar::DIRECTION_OUT], true)
            ? $parsed['direction']
            : null;

        if ($direction === null) {
            $questions[] = 'Was this money in or money out?';
        }

        $amount = null;

        if (($parsed['amount'] ?? null) !== null) {
            try {
                $amount = AmountWords::parse((string) $parsed['amount']);
            } catch (InvalidArgumentException $e) {
                $questions[] = $e->getMessage();
            }
        } else {
            $questions[] = 'How much?';
        }

        // Re-fetched from this tenant rather than trusted from the reply.
        $category = ($parsed['transaction_type_code'] ?? null) !== null
            ? $categories->firstWhere('code', $parsed['transaction_type_code'])
            : null;

        if ($category === null) {
            $questions[] = 'What should this be filed under?';
        }

        $registerAccount = $this->defaultRegisterAccount();

        if ($registerAccount === null) {
            $questions[] = 'There is no cash or bank account set up to book this against.';
        }

        $date = CommandGrammar::date($parsed['date'] ?? null);
        $flags = CommandGrammar::flags($parsed['ambiguity'] ?? null);

        $row->update([
            'resolver' => $this->key(),
            'direction' => $direction,
            'amount' => $amount,
            'transaction_type_id' => $category?->id,
            'register_account_id' => $registerAccount?->id,
            'entry_date' => $date,
            'outcome' => CommandUtterance::OUTCOME_NEEDS_INPUT,
        ]);

        return $this->interpretation(
            $row->refresh(),
            $direction, $amount, $category, $registerAccount, $date,
            $parsed['description'] ?? $category?->name,
            $questions, $flags,
            (float) ($parsed['confidence'] ?? 0),
        );
    }

    public function rehydrate(CommandUtterance $row): ?CommandInterpretation
    {
        if ($row->outcome !== CommandUtterance::OUTCOME_NEEDS_INPUT) {
            return null;
        }

        $row->loadMissing(['transactionType.account', 'registerAccount']);
        $parsed = $row->parsed ?? [];

        return $this->interpretation(
            $row,
            $row->direction,
            $row->amount === null ? null : (float) $row->amount,
            $row->transactionType,
            $row->registerAccount,
            $row->entry_date,
            $parsed['description'] ?? $row->transactionType?->name,
            [],
            CommandGrammar::flags($parsed['ambiguity'] ?? null),
            (float) ($parsed['confidence'] ?? 0),
        );
    }

    public function commit(CommandInterpretation $interpretation): JournalEntry
    {
        $category = $interpretation->category;
        $counterAccount = $category?->account;

        if ($counterAccount === null) {
            throw new InvalidArgumentException(
                'That category points at no account, so there is nothing to book against it.'
            );
        }

        return TenantTransaction::run(fn (): JournalEntry => $this->register->bookRow(
            $interpretation->registerAccount,
            $counterAccount,
            [
                // `direction` is the value the whole feature exists to determine correctly. Everything
                // else about the entry — which leg is the debit, the approval treatment, the posting —
                // belongs to bookRow(), which has done it correctly since long before this existed.
                'direction' => $interpretation->direction,
                'amount' => $interpretation->amount,
                'date' => $interpretation->date->toDateString(),
                'description' => $interpretation->description ?: $category->name,
            ],
        ));
    }

    /**
     * The confirmation's first line, and the most important string in the feature.
     *
     * States the **effect**, never the word the user typed: "money out — 25,000 leaves Cash / Bank", not
     * "credit 25,000". §2.3 — the rule is unambiguous but a speaker using bank-statement English may not
     * be, and this is the only place they can catch it.
     */
    private function interpretation(
        CommandUtterance $row,
        ?string $direction,
        ?float $amount,
        ?TransactionType $category,
        ?Account $registerAccount,
        ?Carbon $date,
        ?string $description,
        array $questions,
        array $flags,
        float $confidence,
    ): CommandInterpretation {
        $effect = null;

        if ($direction !== null && $amount !== null && $registerAccount !== null) {
            $effect = $direction === CommandGrammar::DIRECTION_IN
                ? 'Money in — '.number_format($amount, 2).' arrives in '.$registerAccount->name
                : 'Money out — '.number_format($amount, 2).' leaves '.$registerAccount->name;
        }

        // Complete only when nothing is outstanding AND the category resolved: the effect line can be
        // built from direction and amount alone, and a proposal that reads complete without somewhere to
        // book it would fail at commit instead of asking.
        if ($category === null && $questions === []) {
            $questions[] = 'What should this be filed under?';
        }

        return new CommandInterpretation(
            utterance: $row,
            resolverKey: $this->key(),
            effect: $questions === [] ? $effect : null,
            direction: $direction,
            amount: $amount,
            category: $category,
            registerAccount: $registerAccount,
            date: $date,
            description: $description,
            questions: $questions,
            flags: $flags,
            confidence: $confidence,
        );
    }

    /** @return \Illuminate\Support\Collection<int, TransactionType> */
    private function categories()
    {
        return TransactionType::query()
            ->with(['account', 'aliases'])
            ->where('is_active', true)
            ->whereHas('account', fn ($q) => $q->where('is_active', true)->where('allow_manual_entry', true))
            ->orderBy('name')
            ->get();
    }

    private function defaultRegisterAccount(): ?Account
    {
        return $this->register->registerAccounts()->first();
    }
}
