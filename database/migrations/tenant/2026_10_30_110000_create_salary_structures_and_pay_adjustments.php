<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structures, adjustments, the import batch and the reminder count —
 * docs/saas-launch-plan.md §14.1.
 */
return new class extends Migration
{
    /**
     * Re-runnable, because this one failed half way through on a live tenant.
     *
     * The unique index below carried a name MySQL would not accept, so the deploy of 2026-11-01
     * created `salary_structures`, created `salary_structure_lines`, threw on the index, and left
     * the migration unrecorded — which is the worst of both worlds: the tables exist, so a re-run
     * dies on `Schema::create`, and nothing is recorded, so the migration will be attempted again
     * on every subsequent deploy.
     *
     * The guards below are what let it heal itself on the next run rather than needing somebody to
     * drop tables on a production database by hand. That is a property worth having in a
     * multi-tenant application generally: a migration runs once per company, so a failure on the
     * third tenant of nine always leaves two migrated and six not, and the fix should be "deploy
     * again" rather than "log in and operate".
     */
    public function up(): void
    {
        if (! Schema::hasTable('salary_structures')) {
            Schema::create('salary_structures', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 80)->unique();
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('salary_structure_lines')) {
            Schema::create('salary_structure_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
                $table->foreignId('pay_component_id')->constrained('pay_components')->cascadeOnDelete();
                // share: a percentage of gross; fixed: an amount; balance: whatever gross has left.
                $table->string('mode', 10)->default('share');
                $table->decimal('value', 12, 2)->default(0);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                /*
                 * Named explicitly, because the name Laravel would pick is too long for MySQL.
                 *
                 * The implicit name is `{table}_{columns}_{type}` — here sixty-six characters, and
                 * MySQL caps an identifier at sixty-four. **SQLite has no such limit**, so the whole
                 * test suite passes and the deploy is where it is discovered:
                 *
                 *     SQLSTATE[42000]: 1059 Identifier name '...' is too long
                 *
                 * It stopped a production deploy half way through the first tenant on 2026-11-01,
                 * leaving the new code live against a schema thirty-four migrations behind. Four more
                 * migrations had the same fault waiting; all five are named now.
                 */
                $table->unique(['salary_structure_id', 'pay_component_id'], 'unique_structure_component');
            });
        } elseif (! Schema::hasIndex('salary_structure_lines', 'unique_structure_component')) {
            // The exact state the failed deploy left: the table is there, the index is not.
            Schema::table('salary_structure_lines', function (Blueprint $table): void {
                $table->unique(['salary_structure_id', 'pay_component_id'], 'unique_structure_component');
            });
        }

        if (! Schema::hasTable('pay_adjustments')) {
            Schema::create('pay_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
                $table->string('month', 12);
                $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('reason');
                $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'fiscal_year_id', 'month']);
            });
        }

        Schema::table('payslips', function (Blueprint $table): void {
            $table->string('import_batch', 40)->nullable()->index()->after('sent_at');
            $table->unsignedTinyInteger('acknowledgement_reminders')->default(0)->after('import_batch');
            $table->timestamp('last_reminded_at')->nullable()->after('acknowledgement_reminders');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn(['import_batch', 'acknowledgement_reminders', 'last_reminded_at']);
        });

        Schema::dropIfExists('pay_adjustments');
        Schema::dropIfExists('salary_structure_lines');
        Schema::dropIfExists('salary_structures');
    }
};
