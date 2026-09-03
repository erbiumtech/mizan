<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a dropdown offers, when the answer is this company's own words.
 *
 * The list every company writes differently — its designations, its departments, the
 * plant categories its yard actually holds — used to be a literal array inside a
 * Filament form, so adding "Site Engineer" was a deploy. Rows here instead, one table
 * for all of them, keyed by a list name a module declares in its `module.php`.
 *
 * **Not for the statuses.** A workflow value is code: services branch on
 * `Contract::STATUS_CERTIFIED` and most of those columns are database enums, so a row
 * added here would either do nothing or be rejected on save. Only lists whose column
 * is a plain string and whose value nothing branches on are declared — see
 * App\Support\OptionLists.
 *
 * `value` is what the consuming column stores and `label` is what is shown, which is
 * the whole reason this is not a `string[]`: renaming "Cook" to "Chef" must not orphan
 * every employee row that reads "Cook". The model fixes `value` at creation for that
 * reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_values', function (Blueprint $table) {
            $table->id();

            // The declared list this belongs to, e.g. `employees.designation`.
            $table->string('list')->index();

            $table->string('value')->comment('What the consuming column stores. Fixed at creation.');
            $table->string('label')->comment('What the dropdown shows.');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            // Two rows with the same value in one list is the LeadSource problem: the
            // dropdown offers the same thing twice and every report that groups by it
            // splits the group in half.
            $table->unique(['list', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_values');
    }
};
