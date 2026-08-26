<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Models\TransactionTypeAlias;
use App\Modules\Accounting\Services\CommandBooker;
use App\Modules\Accounting\Services\CommandInterpreter;
use App\Modules\Accounting\Support\LocalPatternModel;
use App\Support\Ai\StructuredModel;
use Database\Seeders\TransactionTypeAliasSeeder;
use Database\Seeders\TransactionTypeSeeder;
use Tests\AccountingTestCase;

/**
 * The command bot with no model behind it — {@see LocalPatternModel}, `docs/ai-command-bot-plan.md` §4.
 *
 * **This is the default driver, and these tests are the argument for it.** Every command below is resolved
 * by a word list, a number parser and a lookup table — no key, no network, no per-command cost — and the
 * answers are identical to what the model produces for the same input. The sign rules, the confirmation
 * and the booking underneath are unchanged, because the driver returns the same shape.
 *
 * Unlike the rest of the suite these tests use the **real** parser rather than a fake. That is the point:
 * the one genuinely non-deterministic step in the feature has been removed, so there is nothing left to
 * stand in for and the whole path can be asserted exactly.
 */
class LocalCommandParserTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'local@test.local'));
        $this->seed(TransactionTypeSeeder::class);
        $this->seed(TransactionTypeAliasSeeder::class);

        config(['ai.enabled' => true, 'ai.driver' => 'local']);
        $this->app->instance(StructuredModel::class, new LocalPatternModel);
    }

    private function interpret(string $utterance)
    {
        return app(CommandInterpreter::class)->interpret($utterance);
    }

    /** @dataProvider commands */
    public function test_it_resolves_a_command(string $utterance, string $direction, float $amount, string $category): void
    {
        $interpretation = $this->interpret($utterance);

        $this->assertTrue($interpretation->isComplete(), $utterance.' → '.implode('; ', $interpretation->questions));
        $this->assertSame($direction, $interpretation->direction, $utterance);
        $this->assertEqualsWithDelta($amount, $interpretation->amount, 0.001, $utterance);
        $this->assertSame($category, $interpretation->category->code, $utterance);
    }

    public static function commands(): array
    {
        return [
            'plain english' => ['rent 25000 out', 'out', 25000.0, 'rent'],
            'category name' => ['utilities 4500 paid', 'out', 4500.0, 'utilities'],
            'roman urdu alias' => ['bijli 4500 diya', 'out', 4500.0, 'utilities'],
            'roman urdu scale' => ['rent 25 hazaar out', 'out', 25000.0, 'rent'],
            'urdu script' => ['کرایہ ۲۵ ہزار دیا', 'out', 25000.0, 'rent'],
            'money in' => ['consulting 1.5 lakh received', 'in', 150000.0, 'service-revenue'],
            'jama means in' => ['sale 8000 jama', 'in', 8000.0, 'sales-revenue'],
            'credit means out' => ['rent 9000 credit', 'out', 9000.0, 'rent'],
            'grouped digits' => ['rent 25,000 out', 'out', 25000.0, 'rent'],
            'k suffix' => ['rent 25k out', 'out', 25000.0, 'rent'],
            'rupee noise' => ['rent Rs. 25,000/- out', 'out', 25000.0, 'rent'],
        ];
    }

    /**
     * **The regression.** `k` is a scale word and every Urdu direction word beginning with k is a trap.
     *
     * "petrol 3000 kharch" captured "3000 k" and resolved to 3,000,000 — a thousand times the figure, and
     * silently, because 3,000,000 is a perfectly valid amount. The scale word now has to be followed by a
     * non-letter.
     */
    public function test_a_following_word_is_not_a_scale_word(): void
    {
        $this->assertEqualsWithDelta(3000.0, $this->interpret('petrol 3000 kharch')->amount, 0.001, 'kharch is not "k"');
        $this->assertEqualsWithDelta(3000.0, $this->interpret('kal petrol 3000 kharch')->amount, 0.001);
        $this->assertEqualsWithDelta(5000.0, $this->interpret('rent 5000 kal diya')->amount, 0.001, 'kal is not "k"');
    }

    /** `kal` resolves to yesterday and says it guessed — the tense that would settle it is usually absent. */
    public function test_kal_defaults_to_yesterday_and_is_flagged(): void
    {
        $interpretation = $this->interpret('kal rent 25000 out');

        $this->assertTrue($interpretation->date->isYesterday());
        $this->assertContains('date', $interpretation->flags);
        $this->assertTrue($interpretation->needsExplicitConfirmation(), 'a guessed date must not be Enter-confirmable');
    }

    public function test_aaj_is_today(): void
    {
        $this->assertTrue($this->interpret('aaj rent 25000 out')->date->isToday());
        $this->assertSame([], $this->interpret('aaj rent 25000 out')->flags, 'today is not a guess');
    }

    // ------------------------------------------------------------------ refusing rather than guessing

    public function test_a_missing_direction_asks(): void
    {
        $interpretation = $this->interpret('rent 25000');

        $this->assertFalse($interpretation->isComplete());
        $this->assertContains('Was this money in or money out?', $interpretation->questions);
    }

    public function test_an_unknown_category_asks(): void
    {
        $interpretation = $this->interpret('gizmo 25000 out');

        $this->assertFalse($interpretation->isComplete());
        $this->assertContains('What should this be filed under?', $interpretation->questions);
        $this->assertNull($interpretation->category, 'never fall back to a catch-all');
    }

    public function test_a_missing_amount_asks(): void
    {
        $interpretation = $this->interpret('rent out');

        $this->assertFalse($interpretation->isComplete());
        $this->assertNull($interpretation->amount);
    }

    /**
     * The longest alias wins, and a shorter one must not shadow it.
     *
     * The collision is built here rather than borrowed from the seeder, because which aliases collide
     * depends on which chart a tenant is on — `rental-income` exists in the personal chart and not the
     * business one, so a test resting on seeded data would assert nothing on half the installs.
     *
     * It matters because the shadowing case is a wrong *category*, not a wrong word: "rent received"
     * resolving on "rent" files a receipt against the rent expense.
     */
    public function test_a_shorter_alias_does_not_shadow_a_longer_one(): void
    {
        $rent = TransactionType::byCode('rent');
        $revenue = TransactionType::byCode('service-revenue');

        TransactionTypeAlias::create(['transaction_type_id' => $rent->id, 'alias' => 'shop']);
        TransactionTypeAlias::create(['transaction_type_id' => $revenue->id, 'alias' => 'shop takings']);

        $this->assertSame('service-revenue', $this->interpret('shop takings 5000 in')->category?->code);
        $this->assertSame('rent', $this->interpret('shop 5000 out')->category?->code, 'the short alias still works alone');
    }

    // ------------------------------------------------------------------ end to end

    /** The sign rules are unchanged: the driver only decides which token is which. */
    public function test_a_locally_parsed_command_books_the_right_legs(): void
    {
        $interpretation = $this->interpret('rent 25000 out');
        $entry = app(CommandBooker::class)->book($interpretation);

        $cash = $interpretation->registerAccount;
        $cashLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', $cash->id)->firstOrFail();

        $this->assertEquals(25000, $cashLine->credit_amount, 'money out credits cash, whatever parsed it');
        $this->assertSame(2, JournalEntryLine::where('journal_entry_id', $entry->id)->count());
    }

    /** No key, no network, and it still reports itself as usable. */
    public function test_it_needs_no_configuration(): void
    {
        config(['ai.claude.api_key' => null]);

        $this->assertTrue((new LocalPatternModel)->isConfigured());
        $this->assertTrue($this->interpret('rent 25000 out')->isComplete());
    }
}
