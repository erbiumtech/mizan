<?php

namespace App\Modules\Expenses\Support;

use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Support\AmountWords;
use App\Modules\Accounting\Support\CommandGrammar;
use App\Modules\Accounting\Support\CommandInterpretation;
use App\Modules\Accounting\Support\CommandResolver;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * "Ali paid 4500 for fuel" — `docs/ai-command-bot-plan.md` §7, Phase 4.
 *
 * The second resolver, and it exists to prove the seam is real rather than because expense claims were the
 * next most urgent thing. It differs from the cash resolver in every way the contract has to tolerate:
 *
 *  - **A different module.** `expenses` is separately licensed and off by default, so a tenant without it
 *    never sees this resolver's slots in the prompt at all — §7's requirement, and the difference between
 *    gating and refusing afterwards.
 *  - **A different slot.** It needs an *employee*, which the cash resolver has no concept of, and it has
 *    **no direction** — a claim is money somebody spent, always.
 *  - **A different ending.** `bookRow()` posts on the spot; a claim is created `pending` and waits for an
 *    approver. So "confirm" does not mean "posted" here, and the effect line says so.
 *
 * If a third resolver ever needs something this contract cannot express, this is the class to compare it
 * against — two implementations is the minimum at which a seam is worth trusting.
 */
class ExpenseClaimCommandResolver implements CommandResolver
{
    public function key(): string
    {
        return 'expense-claim';
    }

    public function label(): string
    {
        return 'Expense claim';
    }

    public function isAvailable(): bool
    {
        return modules()->enabled('expenses')
            && auth()->user()?->can('create', ExpenseClaim::class) !== false;
    }

    /**
     * Words that hand an utterance here rather than to the cash register.
     *
     * "claim" and "reimburse" are unambiguous. The rest is deliberately narrow: an utterance that merely
     * mentions a person is *not* claimed, because "paid Ali 5000" is a cash payment to a supplier at least
     * as often as it is a claim, and a resolver that grabs both would misroute the commoner one.
     */
    public function claims(): array
    {
        return ['\bclaim(s|ed|ing)?\b', '\breimburse(d|ment)?\b', '\bexpense claim\b'];
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function promptSection(): string
    {
        $categories = $this->categories()
            ->map(fn (TransactionType $t): string => sprintf('- %s: %s', $t->code, $t->name))
            ->implode("\n");

        $employees = $this->employees()
            ->map(fn (Employee $e): string => sprintf('- %s: %s', $e->id, $e->full_name ?? $e->name))
            ->implode("\n");

        return <<<PROMPT
        You turn a short expense-claim command into structured fields. You do not do accounting.
        Commands arrive in English, Urdu, or roman Urdu.

        A claim is money an employee spent from their own pocket and wants back. There is no direction to
        report — a claim is always money the employee is owed.

        WHO. The employee who spent it, chosen by id from this list, or null:

        {$employees}

        Match on any part of the name. Return null rather than guessing between two similar names.

        CATEGORY. What it was spent on, chosen from this list, or null:

        {$categories}

        AMOUNT. Return it exactly as written, unparsed. The application understands "25k", "1.5 lakh",
        "۲۵ ہزار" and grouped digits.

        DATE. ISO 8601 (YYYY-MM-DD) for the day the money was spent, resolved against the date in the user
        turn, or null for today. "kal"/کل means both yesterday and tomorrow; resolve it to YESTERDAY when
        the tense does not settle it and add "date" to the ambiguity array.
        PROMPT;
    }

    public function schemaProperties(): array
    {
        return [
            'required' => ['employee_id', 'amount', 'transaction_type_code'],
            'properties' => [
                'employee_id' => [
                    // anyOf rather than a type array: structured outputs refuses `type: [...]` next to an
                    // enum. See CommandGrammar::schemaProperties() for the error it produces.
                    'anyOf' => [
                        ['type' => 'integer', 'enum' => $this->employees()->pluck('id')->all()],
                        ['type' => 'null'],
                    ],
                    'description' => 'The id of the employee who spent the money, from the list in the '
                        .'system prompt. null when no name was given or two names are equally likely.',
                ],
                'amount' => [
                    'type' => ['string', 'null'],
                    'description' => 'The amount exactly as written or said, unparsed.',
                ],
                'transaction_type_code' => [
                    'anyOf' => [
                        ['type' => 'string', 'enum' => $this->categories()->pluck('code')->all()],
                        ['type' => 'null'],
                    ],
                    'description' => 'What it was spent on. null when nothing clearly matches.',
                ],
                'date' => [
                    'type' => ['string', 'null'],
                    'description' => 'ISO 8601 date the money was spent, or null for today.',
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'description' => 'A short note for the claim, in the language it was given in.',
                ],
            ],
        ];
    }

    public function resolve(CommandUtterance $row, array $parsed): CommandInterpretation
    {
        $questions = [];

        $employee = ($parsed['employee_id'] ?? null) !== null
            // Re-fetched inside the tenant: an id from a model reply is a string from outside the system.
            ? Employee::query()->whereKey($parsed['employee_id'])->first()
            : null;

        if ($employee === null) {
            $questions[] = 'Who is claiming this?';
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

        $category = ($parsed['transaction_type_code'] ?? null) !== null
            ? $this->categories()->firstWhere('code', $parsed['transaction_type_code'])
            : null;

        if ($category === null) {
            $questions[] = 'What was it spent on?';
        }

        $date = CommandGrammar::date($parsed['date'] ?? null);
        $flags = CommandGrammar::flags($parsed['ambiguity'] ?? null, ['amount', 'transaction_type_code', 'date', 'description', 'employee_id']);

        $row->update([
            'resolver' => $this->key(),
            'amount' => $amount,
            'transaction_type_id' => $category?->id,
            'entry_date' => $date,
            // The employee is this resolver's own slot, so it goes in `resolved` rather than earning a
            // column on a table it does not own.
            'resolved' => ['employee_id' => $employee?->id],
            'outcome' => CommandUtterance::OUTCOME_NEEDS_INPUT,
        ]);

        return $this->interpretation(
            $row->refresh(), $employee, $amount, $category, $date,
            $parsed['description'] ?? $category?->name,
            $questions, $flags, (float) ($parsed['confidence'] ?? 0),
        );
    }

    public function rehydrate(CommandUtterance $row): ?CommandInterpretation
    {
        if ($row->outcome !== CommandUtterance::OUTCOME_NEEDS_INPUT) {
            return null;
        }

        $row->loadMissing('transactionType.account');
        $parsed = $row->parsed ?? [];
        $employeeId = ($row->resolved ?? [])['employee_id'] ?? null;

        return $this->interpretation(
            $row,
            $employeeId ? Employee::query()->whereKey($employeeId)->first() : null,
            $row->amount === null ? null : (float) $row->amount,
            $row->transactionType,
            $row->entry_date,
            $parsed['description'] ?? $row->transactionType?->name,
            [],
            CommandGrammar::flags($parsed['ambiguity'] ?? null, ['amount', 'transaction_type_code', 'date', 'description', 'employee_id']),
            (float) ($parsed['confidence'] ?? 0),
        );
    }

    public function commit(CommandInterpretation $interpretation): ExpenseClaim
    {
        $employeeId = $interpretation->payload['employee_id'] ?? null;

        if ($employeeId === null) {
            throw new InvalidArgumentException('That claim names no employee, so there is nobody to reimburse.');
        }

        return TenantTransaction::run(fn (): ExpenseClaim => ExpenseClaim::create([
            'employee_id' => $employeeId,
            'transaction_type_id' => $interpretation->category?->id,
            'claimed_on' => $interpretation->date->toDateString(),
            'amount' => $interpretation->amount,
            'description' => $interpretation->description,
            /*
             * Pending, never approved.
             *
             * The cash resolver posts on confirm because `bookRow()` does; this one must not, and the
             * asymmetry is the point rather than an inconsistency. A claim is a request for somebody
             * else's money, and the module already has an approver and an `ExpenseClaimApprove`
             * permission for deciding it. A bot that could self-approve would route around both.
             */
            'status' => ExpenseClaim::STATUS_PENDING,
            'submitted_by' => auth()->id(),
        ]));
    }

    private function interpretation(
        CommandUtterance $row,
        ?Employee $employee,
        ?float $amount,
        ?TransactionType $category,
        $date,
        ?string $description,
        array $questions,
        array $flags,
        float $confidence,
    ): CommandInterpretation {
        $effect = null;

        if ($employee !== null && $amount !== null && $questions === []) {
            // States the effect, and states that it is NOT yet money — §2.3's rule applied to a domain
            // where "confirmed" and "paid" are different things.
            $effect = 'Expense claim — '.number_format($amount, 2).' for '
                .($employee->full_name ?? $employee->name).', pending approval';
        }

        return new CommandInterpretation(
            utterance: $row,
            resolverKey: $this->key(),
            effect: $effect,
            payload: ['employee_id' => $employee?->id],
            amount: $amount,
            category: $category,
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
            // `account` eager-loaded because CommandInterpretation::detailLine() reads through it to name
            // the account on the confirmation, and lazy loading is disabled application-wide. The register
            // resolver loads it for the same reason; a resolver that forgets gets a violation on the
            // confirmation round trip rather than on the first request, which is why it survived until a
            // test drove the whole two-beat flow.
            ->with('account')
            ->where('is_active', true)
            ->whereHas('account', fn ($q) => $q->where('is_active', true)->where('allow_manual_entry', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Employee>
     *
     * Capped, and the cap is a real constraint rather than tidiness: this list is rendered into the
     * cacheable prefix of every command, and a company with two thousand employees would put two thousand
     * names in front of every "rent 25000 out". Above the cap the resolver still works — the model returns
     * null for anyone not listed and the user is asked — which is a worse experience but not a wrong one.
     */
    private function employees()
    {
        return Employee::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(200)
            ->get();
    }
}
