<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joining and leaving: checklists, documents that expire, kit that was issued, and the
 * final settlement.
 *
 * The settlement is the part to read carefully. **It is a proposal, not a posting.** It
 * gathers what the system already knows — the unrecovered advance balance, encashable
 * leave, gratuity, unreturned assets — and produces a figure for approval. Paying it
 * goes through the existing payslip or payment path, which already posts to the ledger
 * correctly. A second money path writing journal entries of its own is how a ledger
 * stops reconciling. docs/hrms-plan.md §4.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->comment('onboarding|exit');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->string('title');

            // A role name rather than a person: "IT", "HR", "Line manager". Templates
            // outlive the people who happen to hold those jobs, and a template pointing
            // at somebody who left is a checklist nobody owns.
            $table->string('owner_role')->nullable();

            // Signed: -7 means a week before the joining or leaving date, which is when
            // most onboarding actually has to happen.
            $table->smallInteger('due_offset_days')->default(0)
                ->comment('Days from the joining/leaving date. Negative is before it.');

            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // The template may be edited or retired after a checklist is started, so
            // the items are COPIED onto employee_checklist_items rather than read
            // through. A leaver's checklist must say what they were actually asked to
            // do, not what the template says today.
            $table->foreignId('checklist_template_id')->nullable()->constrained('checklist_templates')->nullOnDelete();

            $table->string('kind');
            $table->date('started_on');
            $table->date('completed_on')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'kind']);
        });

        Schema::create('employee_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_checklist_id')->constrained('employee_checklists')->cascadeOnDelete();

            // The title is copied, not joined, for the reason above.
            $table->string('title');
            $table->string('owner_role')->nullable();

            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->index();
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('kind')->comment('cnic|passport|visa|licence|degree|contract|other');
            $table->string('number')->nullable();
            $table->date('issued_on')->nullable();

            // The reason this table exists. Gets the same reminder machinery the
            // project certificate checks already prove out: a daily command,
            // thresholds in config, and notifications on TRANSITIONS rather than on
            // every run — a job that mails the same warning for thirty days trains
            // somebody to filter it.
            $table->date('expires_on')->nullable();

            $table->string('file_path')->nullable();
            $table->foreignId('verified_by')->nullable()->index();
            $table->timestamp('verified_at')->nullable();

            // Which threshold has already been warned about, so the next run only
            // notifies when the answer changes. Null until the first warning.
            $table->unsignedSmallInteger('expiry_notified_at_days')->nullable()
                ->comment('The threshold last warned at. Notify on transitions, never on every run.');

            $table->timestamps();

            $table->index(['expires_on']);
        });

        Schema::create('issued_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('asset_kind')->comment('laptop|phone|sim|vehicle|access_card|other');
            $table->string('description');
            $table->string('serial_no')->nullable();

            // Points at Accounting's fixed_assets when the laptop is on the books; a
            // phone that was never capitalised has a description and no link. Guarded
            // on `accounting`, and nullOnDelete so disposing of the asset does not take
            // the record of who had it.
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();

            $table->date('issued_on');
            $table->date('returned_on')->nullable();
            $table->text('condition_note')->nullable();

            // What it would cost if it is not returned. Feeds the final settlement, so
            // it is worth having even for kit that is not capitalised.
            $table->decimal('value', 15, 2)->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'returned_on']);
        });

        Schema::create('final_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('left_on');

            // Every figure is nullable and every one is a PROPOSAL. Nothing here posts.
            $table->decimal('notice_recovery', 15, 2)->default(0)
                ->comment('Recovered for notice not served');
            $table->decimal('leave_encashment_days', 6, 1)->default(0);
            $table->decimal('leave_encashment_amount', 15, 2)->default(0);
            $table->decimal('gratuity_amount', 15, 2)->default(0);
            $table->decimal('outstanding_advance', 15, 2)->default(0);
            $table->decimal('unreturned_asset_value', 15, 2)->default(0);
            $table->decimal('other_deductions', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);

            $table->string('status')->default('draft')
                ->comment('draft|approved|paid');

            $table->foreignId('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();

            // How it was actually paid. Both nullable and both soft in intent: the
            // settlement records which existing path settled it, and neither path
            // knows about this table.
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            // One settlement per employee. Somebody re-employed and leaving again is
            // rare enough that a second row should be a deliberate act, not something
            // a double-clicked button produces.
            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_settlements');
        Schema::dropIfExists('issued_assets');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_checklist_items');
        Schema::dropIfExists('employee_checklists');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_templates');
    }
};
