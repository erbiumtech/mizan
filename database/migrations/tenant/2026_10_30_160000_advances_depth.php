<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advances get types, an application with approval, and a pause — docs/saas-launch-plan.md §14.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->unsignedSmallInteger('max_instalments')->nullable();
            $table->decimal('interest_rate_pct', 6, 2)->default(0);
            $table->unsignedSmallInteger('active_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('advances', function (Blueprint $table): void {
            $table->foreignId('advance_type_id')->nullable()->after('employee_id')->constrained('advance_types')->nullOnDelete();
            $table->decimal('interest_amount', 15, 2)->default(0)->after('total_amount');
            $table->unsignedSmallInteger('instalments')->nullable()->after('monthly_instalment');
            $table->foreignId('requested_by')->nullable()->index()->after('notes');
            $table->timestamp('requested_at')->nullable()->after('requested_by');
            $table->foreignId('decided_by')->nullable()->index()->after('requested_at');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('refusal_reason')->nullable()->after('decided_at');
            $table->timestamp('paused_at')->nullable()->after('refusal_reason');
            $table->string('paused_reason')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('advance_type_id');
            $table->dropColumn(['interest_amount', 'instalments', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'refusal_reason', 'paused_at', 'paused_reason']);
        });

        Schema::dropIfExists('advance_types');
    }
};
