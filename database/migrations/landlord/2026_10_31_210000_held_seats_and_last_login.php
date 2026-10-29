<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Held seats and last sign-in — docs/saas-launch-plan.md §14.9. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            /*
             * Seats a company holds above its headcount.
             *
             * A company hiring twenty people next month does not want its bill to lurch,
             * and more to the point does not want the seat count to be a surprise either
             * way. The bill counts the greatest of headcount, held seats and the plan
             * minimum, so holding seats raises the floor and never the ceiling: hire
             * thirty into twenty held seats and thirty is what is billed.
             */
            $table->unsignedInteger('seats_reserved')->default(0)->after('seats');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Read off the sign-in log until now, which meant a join and a sort over every
            // row in `activity_log` to answer "who has not been here since March".
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['last_login_at', 'last_login_ip']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('seats_reserved'));
    }
};
