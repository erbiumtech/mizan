<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commercial record on a company — docs/saas-launch-plan.md §4.
 *
 * Every column is nullable with no default and no backfill, so every company
 * that exists today — the operator's own included — is untouched and means
 * "licensed by hand, billed by nobody", which is exactly what it is.
 *
 *  - `plan`               a key in config/plans.php; what the customer pays for
 *  - `trial_ends_at`      set at signup, cleared on subscribing
 *  - `billing_contact_id` the customer's `contacts` row IN THE OPERATOR TENANT'S
 *                         DATABASE — no foreign key, because the row lives in
 *                         another schema. The one place the landlord points into
 *                         a tenant; the metering job is its only reader.
 *  - `billing_currency`   PKR | USD | EUR, fixed at subscription
 *  - `seats`, `seats_counted_at`
 *                         the latest count only. The invoice is the record of
 *                         what was billed, as everywhere else here.
 *  - `suspended_reason`   trial_expired | overdue | null. Null with status 0 is a
 *                         person's decision, which the enforcement job never
 *                         lifts; the other two are its own and it may.
 *  - `domain`             a custom host, unique, lowercase (§8.8)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('plan')->nullable()->after('legal_entity');
            $table->dateTime('trial_ends_at')->nullable()->after('plan');
            $table->unsignedBigInteger('billing_contact_id')->nullable()->after('trial_ends_at');
            $table->char('billing_currency', 3)->nullable()->after('billing_contact_id');
            $table->unsignedInteger('seats')->nullable()->after('billing_currency');
            $table->dateTime('seats_counted_at')->nullable()->after('seats');
            $table->string('suspended_reason')->nullable()->after('status');
            $table->string('domain')->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique(['domain']);
            $table->dropColumn([
                'plan', 'trial_ends_at', 'billing_contact_id', 'billing_currency',
                'seats', 'seats_counted_at', 'suspended_reason', 'domain',
            ]);
        });
    }
};
