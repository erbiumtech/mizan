<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `regime` becomes a plain string, as it already is on tax_surcharges.
 *
 * The column was an enum of the four regimes that existed the day the table was
 * created, which made adding a fifth — export of services under s.154A, the
 * final-tax regime the return pack needs — an ALTER TABLE instead of a seeder
 * row. The valid names live in App\Support\TaxRegimes, which is the registry a
 * Finance Act actually changes; a database enum repeating that list can only
 * ever lag it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_schedules', function (Blueprint $table) {
            $table->string('regime', 30)->change();
        });
    }

    public function down(): void
    {
        // Not narrowed back to the enum: rows with the newer regimes would make
        // the reverse ALTER fail or truncate data. The string accepts everything
        // the enum did.
    }
};
