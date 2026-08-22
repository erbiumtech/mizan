<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job is one contract to build one thing at one place.
 *
 * `docs/construction-management-plan.md` §1. The unit that gets a contract sum, a cost code structure, a
 * site, a team, a programme, certificates, retention and a final account — everything else in that plan
 * hangs off it.
 *
 * **Not an extension of `projects`**, which is finding #1 of that plan: `Project` is a software delivery
 * engagement with environment health checks, certificate expiry and a public status page. Making a
 * construction job a subtype of it would put `health_status` on a bridge deck and make construction
 * unsellable to a company that never bought Projects. `project_id` below is the whole of the relationship:
 * nullable, guarded, and never required.
 *
 * **The revised contract sum is not here.** Original sum plus approved variations, computed through one
 * accessor once §9 exists — the checklist's computed-not-stored rule, which applies with unusual force to a
 * number two people will quote in a meeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_jobs', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique()->comment('J-2026-014');
            $table->string('name');
            $table->text('description')->nullable();

            // Contracts are routinely awarded and certified in parts — three towers taken over
            // separately, a highway in four lots each with its own completion date and liquidated
            // damages. Cost and certificates attach to any job in the tree and every report rolls up
            // descendants through `path`. §1.2.
            $table->foreignId('parent_id')->nullable()->constrained('construction_jobs')->nullOnDelete();
            $table->string('path')->nullable()->comment('Materialised ancestor path, /12/47/');

            $table->foreignId('client_contact_id')->nullable()->constrained('contacts')->nullOnDelete()
                ->comment('Nullable until award');

            // Guarded, never required: offered only when `projects` is licensed, exactly as
            // invoices.project_id already works. Buys a company running both the ability to see a job's
            // hours in the timesheet module, and nothing else. §1.1 and §18.1.
            $table->unsignedBigInteger('project_id')->nullable();

            $table->enum('nature', ['building', 'civils', 'infrastructure', 'fit_out', 'mep', 'marine', 'other'])
                ->default('building');

            $table->enum('status', [
                'tender', 'awarded', 'mobilising', 'in_progress', 'suspended',
                'substantial_completion', 'defects_liability', 'final_account', 'closed',
                'cancelled', 'lost',
            ])->default('tender');

            // Which contract family's vocabulary and arithmetic this job follows. One set of tables,
            // selected per job — §8.3 is what this actually drives.
            $table->enum('contract_standard', ['fidic', 'aia', 'custom'])->default('fidic');

            $table->string('currency_code', 3)->nullable();

            $table->string('site_address_line_1')->nullable();
            $table->string('site_address_line_2')->nullable();
            $table->string('site_city')->nullable();
            $table->string('site_country')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->date('commencement_date')->nullable();
            $table->date('planned_completion_date')->nullable();
            // Moves only through an approved extension of time (§13), never by hand.
            $table->date('revised_completion_date')->nullable();
            $table->date('actual_completion_date')->nullable();
            // The Taking-Over date under FIDIC, the Substantial Completion date under AIA — one column,
            // two labels.
            $table->date('substantial_completion_date')->nullable();
            $table->unsignedSmallInteger('defects_period_days')->nullable();
            $table->date('final_certificate_date')->nullable();

            $table->decimal('contract_sum', 15, 2)->nullable()->comment('Original; revised is computed');

            $table->decimal('retention_pct', 5, 2)->nullable();
            // Retention stops accruing at this share of the contract sum — the "limit of retention".
            $table->decimal('retention_cap_pct', 5, 2)->nullable();
            $table->decimal('retention_first_release_pct', 5, 2)->nullable();

            $table->decimal('advance_payment_pct', 5, 2)->nullable();
            $table->decimal('advance_recovery_start_pct', 5, 2)->nullable();
            $table->decimal('advance_recovery_rate_pct', 5, 2)->nullable();

            $table->decimal('liquidated_damages_per_day', 15, 2)->nullable();
            $table->decimal('liquidated_damages_cap_pct', 5, 2)->nullable();

            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            // The Engineer under FIDIC, the Architect under AIA.
            $table->foreignId('certifier_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Guarded on `employees`, like project_id on `projects`: without it
            // construction_workers carries everyone and created_by answers ownership. §18.1.
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->unsignedBigInteger('qs_employee_id')->nullable();
            $table->unsignedBigInteger('site_agent_employee_id')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('path');
            $table->index('parent_id');
        });

        // Mirrors project_employee exactly, and is what JobAccess reads: a site engineer sees the sites
        // they are on, a commercial manager sees all of them by permission rather than by assignment. §1.
        Schema::create('construction_job_employee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->string('role')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable()->comment('Null = open stint');
            $table->timestamps();

            $table->unique(['job_id', 'employee_id', 'from_date']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_job_employee');
        Schema::dropIfExists('construction_jobs');
    }
};
