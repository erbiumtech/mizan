<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contracts, checklist item kinds, separations with clearance — docs/saas-launch-plan.md §14.5. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('number', 40)->unique();
            $table->string('type', 20)->default('permanent');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->decimal('gross', 15, 2)->default(0);
            $table->unsignedSmallInteger('probation_months')->nullable();
            $table->string('status', 12)->default('draft');
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_ip', 45)->nullable();
            $table->json('witnesses')->nullable();
            $table->boolean('ip_disclosure')->default(false);
            $table->foreignId('signed_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::table('checklist_items', function (Blueprint $table): void {
            $table->string('kind', 10)->default('manual')->after('title');
            $table->string('link_url')->nullable()->after('kind');
            $table->string('auto_check', 30)->nullable()->after('link_url');
        });

        Schema::table('employee_checklist_items', function (Blueprint $table): void {
            $table->string('kind', 10)->default('manual')->after('title');
            $table->string('link_url')->nullable()->after('kind');
            $table->string('auto_check', 30)->nullable()->after('link_url');
            $table->timestamp('waived_at')->nullable()->after('completed_by');
            $table->unsignedBigInteger('waived_by')->nullable()->after('waived_at');
            $table->string('waive_reason')->nullable()->after('waived_by');
        });

        Schema::create('separations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('notice_on')->nullable();
            $table->date('last_working_day');
            $table->text('reason')->nullable();
            $table->string('status', 10)->default('open');
            $table->date('closed_on')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('clearance_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('separation_id')->constrained('separations')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 10)->default('manual');
            $table->string('source', 20)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->unsignedBigInteger('cleared_by')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->unsignedBigInteger('waived_by')->nullable();
            $table->string('waive_reason')->nullable();
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_items');
        Schema::dropIfExists('separations');
        Schema::table('employee_checklist_items', fn (Blueprint $t) => $t->dropColumn(['kind', 'link_url', 'auto_check', 'waived_at', 'waived_by', 'waive_reason']));
        Schema::table('checklist_items', fn (Blueprint $t) => $t->dropColumn(['kind', 'link_url', 'auto_check']));
        Schema::dropIfExists('employment_contracts');
    }
};
