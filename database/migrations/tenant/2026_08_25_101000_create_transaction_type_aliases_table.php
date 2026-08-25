<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other words a category goes by — `docs/ai-command-bot-plan.md` §3.2.
 *
 * "Aliases are data, not prompt text." A tenant teaches the bot that *kiraya* and کرایہ are rent without
 * a code change and without a prompt of their own: the template is fixed, the rows vary, and they are
 * rendered into the cacheable half of the prompt so the model matches a real category rather than
 * inventing one.
 *
 * **Keyed on `transaction_type_id`, never on the category's name.** A tenant who renames "Rent" to
 * "Office Rent" keeps every alias, because none of them mentions the name. The plan's own risk entry says
 * a *merge* is the case this does not survive — two categories collapsing into one leaves the loser's
 * aliases pointing at a deleted row, which is what the cascade below is for.
 *
 * `alias` is stored lower-cased and unique across the tenant. Unique, because an alias that resolved to
 * two categories would resolve to neither — the bot would have to ask, which is exactly the outcome the
 * alias existed to avoid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_type_aliases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_type_id')->constrained()->cascadeOnDelete();

            // Lower-cased on the way in by the model's mutator. Not case-insensitively collated here:
            // SQLite and MySQL disagree about what that means for non-ASCII, and Urdu is non-ASCII.
            $table->string('alias');

            /*
             * Which language this spelling belongs to — `ur` for کرایہ, `ur-Latn` for the roman-Urdu
             * "kiraya" that most people actually type on a phone, `en` for an English synonym.
             *
             * Advisory rather than enforced: it is here so a tenant can list or prune one language's
             * aliases, not so the resolver can filter by it. A person typing roman Urdu into an English
             * interface is the common case, not the exception, so filtering by the UI locale would
             * discard the aliases most likely to be needed.
             */
            $table->string('locale', 12)->nullable();

            $table->timestamps();

            $table->unique('alias');
            $table->index('transaction_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_type_aliases');
    }
};
