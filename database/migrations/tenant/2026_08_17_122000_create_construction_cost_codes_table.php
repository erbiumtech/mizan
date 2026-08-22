<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cost-code library: *what kind* of cost something is.
 *
 * `docs/construction-management-plan.md` §2. The counterpart to the WBS, which answers *what* is being built —
 * and the intersection of the two is the control account that carries a budget, which is ANSI/EIA-748's
 * definition and what makes earned value computable at all (§14).
 *
 * **Per tenant, not per job, and that is the entire point of having them** (§2.2). "What did formwork to
 * soffits cost us per square metre, across the last six jobs" is the question a contractor prices the next
 * tender with, and it is unanswerable the moment each job invents its own codes. A job *selects* codes rather
 * than owning them: opening a budget line against a code is what puts it on the job. The escape hatch for a
 * genuine one-off is `is_active = false` at library level — a deliberate, visible act — rather than a private
 * numbering scheme per job.
 *
 * **One tree, many mappings — not many trees** (§2.1). A contractor is asked for the same money in four shapes
 * in the same month: the tender came in CSI MasterFormat divisions, the QS measured under RICS NRM, the design
 * team codes in Uniclass or OmniClass, and the client's cost report has to arrive in ICMS 3 categories so the
 * number can be compared with a project in another country. A tree per standard means every cost is classified
 * four times, the four drift, and reconciling them becomes a monthly job for a person. So the standard codes
 * are **columns on one tree the company actually uses**: every report is a `group by`, the mapping is
 * maintained once per code rather than once per transaction, and an unmapped code shows up as a single
 * "unmapped" row — visible, countable, fixable — rather than as a silently wrong total.
 *
 * **ICMS earns two columns.** ICMS 3 fixes Level 2 as six cost categories (Acquisition, Construction, Renewal,
 * Operation, Maintenance, End of life) and mandates standardised Level 3 groups beneath them, specifically so
 * projects under different national standards can be compared. Level 4 is the company's own — which is exactly
 * `icms_category` + `icms_group` on a code whose own numbering is whatever the company has always used.
 *
 * **Nothing proprietary is seeded.** MasterFormat is CSI's, Uniclass NBS's, NRM RICS's and ICMS the
 * Coalition's, and redistribution terms cannot be cleared from here — so Phase 0 decided the structure and a
 * CSV import ship, and the seed packs are a later per-standard deliverable gated on written terms. This
 * supersedes §2.2's closing paragraph, which assumed a seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_cost_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('construction_cost_codes')->cascadeOnDelete();
            $table->string('path')->nullable()->comment('Materialised ancestor path, /12/47/');

            // Company-wide unique: the whole value of the library is that one code means one thing on every
            // job, which is what makes the cross-job rate question answerable.
            $table->string('code')->unique();
            $table->string('name');

            // The standard five. Every cost entry carries one, and §3's cost report groups by it before it
            // groups by anything else — a contractor asks "how much of this job is labour" first.
            $table->enum('cost_type', ['labour', 'material', 'plant', 'subcontract', 'other'])->default('other');

            $table->string('unit', 16)->nullable()->comment('m3, m2, t, hr, sum');

            $table->boolean('is_leaf')->default(true);
            // The escape hatch of §2.2: a one-off provisional item is switched off at library level rather
            // than invented per job.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            // The classification mapping of §2.1. Nullable by design: an unmapped code is a countable row in
            // the report for that standard, not a blocked save.
            $table->string('masterformat_code')->nullable();
            $table->string('uniformat_code')->nullable();
            $table->string('uniclass_code')->nullable();
            $table->string('omniclass_code')->nullable();
            $table->string('nrm_code')->nullable();
            // ICMS 3 Level 2 — one of A, C, R, O, M, E.
            $table->string('icms_category', 1)->nullable();
            // ICMS 3 Level 3 — the mandated cost group under that category.
            $table->string('icms_group')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('path');
            $table->index('parent_id');
            $table->index('cost_type');
            // The ICMS report groups on the pair, and it is the one report that must be produced for an
            // external audience.
            $table->index(['icms_category', 'icms_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_cost_codes');
    }
};
