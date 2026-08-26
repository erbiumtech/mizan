<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kind of thing a command became — `docs/ai-command-bot-plan.md` §7, Phase 4.
 *
 * Until now every command was a register row, so the table's shape *was* the cash resolver's shape. With
 * a second resolver that is no longer true: an expense claim has an employee and no register account, and
 * a construction cost would have a job and a WBS node.
 *
 * **The cash columns stay, and the reason is foreign keys rather than habit.** `transaction_type_id` and
 * `register_account_id` are real constraints that null themselves when the row they point at is deleted,
 * and they carry the indexes §9's reports read. A JSON blob gives neither. So the columns are kept for the
 * slots that are genuinely shared — amount, date, category — and `resolved` carries whatever else a
 * resolver needs, with weaker guarantees, which is the honest trade for not making every module migrate a
 * table it does not own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_utterances', function (Blueprint $table) {
            /*
             * The resolver's `key()`, never a class name.
             *
             * A class name in a column is the thing `ModuleMap::alias()` exists to prevent — it breaks the
             * day a module is renamed or moved, and these rows outlive refactors by design.
             *
             * Nullable because a command that failed before routing (an empty utterance, an unreachable
             * model) never reached a resolver, and recording a resolver it did not use would be a lie in
             * the one table that exists to say what happened.
             */
            $table->string('resolver', 32)->nullable()->after('locale')->index();

            // Slots that are not shared: an employee id, a job, a WBS node. Read only by the resolver
            // that wrote them, which is why nothing here is promoted to a column.
            $table->json('resolved')->nullable()->after('parsed');
        });
    }

    public function down(): void
    {
        Schema::table('command_utterances', function (Blueprint $table) {
            $table->dropIndex(['resolver']);
            $table->dropColumn(['resolver', 'resolved']);
        });
    }
};
