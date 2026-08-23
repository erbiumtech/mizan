<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ISO 19650 document register — `docs/construction-management-plan.md` §15.
 *
 * The standard asks for four things: every information container has a unique identifier and a standard name;
 * every container carries a **suitability status** saying what it may be used for; revisions are controlled so
 * a superseded one is not in use; and containers move through **work in progress → shared → published →
 * archived** with an approval gate between the states. All four are here; the state machine itself lives in a
 * service, never in a form (§15).
 *
 * **It lives in the spine, not the field module** (§18). RFIs reference drawings, submittals *are* documents,
 * transmittals carry contract notices and ITPs reference approved-for-construction drawings — so a customer
 * who bought contracts and quality but not site operations would otherwise have no register at all.
 *
 * Two things this deliberately is not, per §15.1: a BIM viewer and a model server. A `model` document type
 * exists so the register is *complete* — the IFC or RVT file is stored with its metadata and its hash and
 * people download it into whatever viewer they own — and the application never opens it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Re-runnable by table. MySQL does not roll back DDL, so a `create` that fails part-way leaves the
         * tables before it standing while the migration stays unrecorded — and the retry then dies on its own
         * output rather than on the original fault. Each table is created only if it is absent so the retry
         * carries on from where it stopped.
         */
        $createIfMissing = function (string $table, Closure $callback): void {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $callback);
            }
        };

        /*
         * The naming convention is a **per-job record, not a hardcoded list**, and §15 is emphatic about why:
         * ISO 19650 mandates the *fields*, and every project issues its own code lists for what goes in them.
         * Hardcoding the codes makes the module unusable on job number two.
         *
         * The code lists are JSON because they are per-project reference data of unpredictable length that is
         * only ever read as a whole — a table of allowed values per field per job would be five joins to
         * validate one filename.
         */
        $createIfMissing('construction_naming_conventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->string('name');
            $table->string('separator', 4)->default('-');
            // The field order in the assembled identifier, so one project can put discipline before volume.
            $table->json('field_order')->nullable();
            // field => [allowed codes]. Empty or absent means "any value", which is what a project that has
            // not issued a list yet actually wants — not a blocked upload.
            $table->json('code_lists')->nullable();
            // Suitability is a string against this list rather than an enum: S0–S7 with A1–A5 and B1–B5 is the
            // UK ISO 19650-2 set, while AIA-land issues "for construction", "for approval" and "as-built".
            // An enum here fails on the second project.
            $table->json('suitability_codes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('job_id');
        });

        $createIfMissing('construction_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            // The assembled name, unique per job. Stored as well as derived so it can be searched, quoted in a
            // transmittal and printed without re-assembling it seven times.
            $table->string('information_container_id');

            // Each naming field as its own column, so the identifier can be both re-assembled **and
            // validated**. One concatenated string would make "which projects use originator ABC" unanswerable.
            $table->string('project_code')->nullable();
            $table->string('originator_code')->nullable();
            $table->string('functional_code')->nullable()->comment('Volume or system');
            $table->string('spatial_code')->nullable()->comment('Level or location');
            $table->string('form_code')->nullable()->comment('Drawing, specification, method statement, …');
            $table->string('discipline_code')->nullable();
            $table->string('container_number')->nullable();

            $table->foreignId('naming_convention_id')->nullable()
                ->constrained('construction_naming_conventions')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('document_type', [
                'drawing', 'specification', 'method_statement', 'calculation', 'schedule',
                'report', 'certificate', 'correspondence', 'model', 'photograph', 'other',
            ])->default('drawing');

            // The four ISO 19650 states.
            $table->enum('cde_state', ['work_in_progress', 'shared', 'published', 'archived'])
                ->default('work_in_progress');

            $table->unsignedBigInteger('current_revision_id')->nullable()
                ->comment('Set after the revision exists; FK added below to avoid a circular create');

            $table->foreignId('originator_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();

            // A contractual document is one whose issue has a consequence under the contract — a drawing
            // forming part of the works information, an instruction, a notice.
            $table->boolean('is_contractual')->default(false);

            // **Advisory, not enforced** — §15.2. TenantFileController checks company membership and nothing
            // else, so any user of the company holding the URL can stream the file. The field records intent
            // and the form says so plainly; making it real is a Core change, deliberately not smuggled in
            // behind a construction migration.
            $table->enum('confidentiality', ['normal', 'restricted'])->default('normal');

            $table->foreignId('superseded_by_document_id')->nullable()
                ->constrained('construction_documents')->nullOnDelete();

            $table->timestamps();

            $table->unique(['job_id', 'information_container_id']);
            $table->index(['job_id', 'cde_state']);
            $table->index('document_type');
        });

        $createIfMissing('construction_document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('construction_documents')->cascadeOnDelete();

            $table->string('revision')->comment('P01, C02, A, 3 — whatever the project uses');

            // A string against the job's convention, never an enum. See the naming-convention table.
            $table->string('suitability_code')->nullable();
            $table->enum('cde_state_at_issue', ['work_in_progress', 'shared', 'published', 'archived'])
                ->default('work_in_progress');
            $table->enum('status', ['draft', 'issued', 'superseded'])->default('draft');
            $table->string('reason_for_issue')->nullable();

            $table->date('issued_on')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->date('received_on')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_mime')->nullable();
            // A sha256, and the only reliable answer to "is this the same drawing" — it is what catches a
            // re-issue with no changes, which is otherwise invisible and wastes everybody's review time.
            $table->string('file_hash', 64)->nullable();

            $table->string('scale')->nullable();
            $table->string('sheet_size')->nullable();
            $table->string('drawn_by')->nullable();
            $table->string('checked_by')->nullable();

            // The approval gate between shared and published.
            $table->enum('approval_status', ['not_required', 'pending', 'approved', 'rejected'])
                ->default('not_required');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_comments')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'revision']);
            $table->index('file_hash');
            $table->index('status');
        });

        // Deferred because the two tables reference each other; guarded for the same reason the creates are.
        $hasRevisionKey = collect(Schema::getForeignKeys('construction_documents'))
            ->contains(fn (array $key) => in_array('current_revision_id', $key['columns'], true));

        if (! $hasRevisionKey) {
            Schema::table('construction_documents', function (Blueprint $table) {
                $table->foreign('current_revision_id')
                    ->references('id')->on('construction_document_revisions')->nullOnDelete();
            });
        }

        $createIfMissing('construction_transmittals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->string('reference')->comment('TR-014');
            $table->string('subject');
            $table->text('notes')->nullable();
            $table->enum('purpose', ['for_action', 'for_information', 'for_approval', 'for_construction'])
                ->default('for_information');
            $table->date('issued_on')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->enum('status', ['draft', 'issued'])->default('draft');
            $table->timestamps();

            $table->unique(['job_id', 'reference']);
        });

        $createIfMissing('construction_transmittal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transmittal_id')->constrained('construction_transmittals')->cascadeOnDelete();
            // The *revision*, not the document: a transmittal is a record of which version went out, and
            // pointing at the document alone would make it say something different after the next issue.
            $table->foreignId('document_revision_id')->constrained('construction_document_revisions')->cascadeOnDelete();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('media')->nullable()->comment('PDF, paper, A1 print');
            $table->timestamps();

            $table->unique(['transmittal_id', 'document_revision_id'], 'transmittal_items_revision_unique');
        });

        /*
         * The acknowledgement record is the point of the whole table: **a transmittal nobody acknowledged is a
         * drawing somebody will later say they never received**, and that argument is worth money on a claim.
         */
        $createIfMissing('construction_transmittal_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transmittal_id')->constrained('construction_transmittals')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name')->nullable()->comment('For a recipient who is not a contact');
            $table->string('email')->nullable();
            $table->enum('role', ['action', 'information', 'approval'])->default('information');

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by_name')->nullable();
            $table->timestamp('chased_at')->nullable();

            $table->timestamps();

            $table->index('transmittal_id');
        });
    }

    public function down(): void
    {
        Schema::table('construction_documents', function (Blueprint $table) {
            $table->dropForeign(['current_revision_id']);
        });

        Schema::dropIfExists('construction_transmittal_recipients');
        Schema::dropIfExists('construction_transmittal_items');
        Schema::dropIfExists('construction_transmittals');
        Schema::dropIfExists('construction_document_revisions');
        Schema::dropIfExists('construction_documents');
        Schema::dropIfExists('construction_naming_conventions');
    }
};
