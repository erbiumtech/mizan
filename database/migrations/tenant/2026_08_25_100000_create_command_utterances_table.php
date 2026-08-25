<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody said to the command bot, and what the machine made of it —
 * `docs/ai-command-bot-plan.md` §9.
 *
 * Two things need this and neither is optional.
 *
 * **Correction.** When somebody asks why the ledger says what it says, the answer has to include the
 * sentence that caused it and the interpretation that was accepted. A posted journal entry records the
 * figures; it records nothing about the words that produced them, and "the bot did it" is not an audit
 * trail.
 *
 * **Improvement.** §3.2's alias table is only maintainable if there is a record of which utterances failed
 * to resolve. A row here with a null `transaction_type_id` and a resolved-to-nothing category is the raw
 * material for teaching the system that *kiraya* means rent.
 *
 * **A row is written whether or not anything was booked**, which is the point — an abandoned command is
 * more interesting than a successful one. `journal_entry_id` null plus an `outcome` says what happened.
 *
 * No `company_id`: the table is on the tenant connection, so the company is the database. `user_id` is
 * indexed rather than constrained because `users` lives on the landlord connection and a foreign key
 * across connections is not a constraint — the same reasoning `saved_report_views` gives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_utterances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->index();

            // What was typed, and — once voice lands — what the transcriber heard. Kept apart on purpose:
            // §5 requires a speech error and a parse error to be separately visible, and one column
            // holding whichever happened would make them indistinguishable after the fact.
            $table->text('utterance');
            $table->text('transcript')->nullable();
            $table->string('locale', 12)->nullable();

            // The model's structured reply, verbatim, before any resolution. The `ambiguity` array and
            // `confidence` live in here rather than in columns of their own: they are the model's opinion
            // about its own answer, not facts about the transaction, and promoting them to columns would
            // invite querying them as though they were.
            $table->json('parsed')->nullable();

            // What the resolution actually settled on. Nullable throughout, because a command that was
            // abandoned half-resolved is exactly the row worth keeping.
            $table->string('direction', 8)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types')->nullOnDelete();
            $table->foreignId('register_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->date('entry_date')->nullable();

            /*
             * How it ended.
             *
             *   booked      — confirmed and posted; `journal_entry_id` is set
             *   needs_input — shown to the user with something unresolved, and left there
             *   cancelled   — shown and dismissed
             *   failed      — the model could not be reached, or replied unusably
             */
            $table->string('outcome', 16)->index();
            $table->text('outcome_reason')->nullable();

            // Set on delete rather than cascade: deleting the entry (a correction from the register) must
            // not erase the record of the sentence that created it — that is precisely when somebody is
            // asking what happened.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();

            // The two reads this table gets: one person's recent commands, and "what failed to resolve".
            $table->index(['user_id', 'created_at']);
            $table->index(['outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_utterances');
    }
};
