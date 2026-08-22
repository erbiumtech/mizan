<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two per-job trees: what is being built, and where it is.
 *
 * **The WBS answers "what is being built, and where"** — `docs/construction-management-plan.md` §2. It is per
 * job, and a node is a *deliverable or a location*, never a cost type: "Tower B / Level 4 / Facade" is a WBS
 * node and "welding labour" is not. What kind of cost something is lives in the per-tenant cost-code library
 * (§2.1), and the intersection of the two is the control account that carries a budget — which is
 * ANSI/EIA-748's definition, and what makes earned value computable at all (§14).
 *
 * **Locations answer "where" for the site half** — §16.5. Punch items, diary photos, inspections, NCRs and
 * incidents all need to say where they are, and five free-text location columns is five spellings of
 * "Level 3 East", which makes the report that matters most before handover — *every open item in this room* —
 * impossible to write.
 *
 * Two trees rather than one, and deliberately: the WBS is a commercial breakdown that carries budget and gets
 * measured, and locations are physical places that carry observations. A tower's facade package is a WBS node;
 * room 412 is a location. Merging them would put a budget on a room and an inspection on a cost package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_wbs_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('construction_wbs_nodes')->cascadeOnDelete();
            $table->string('path')->nullable()->comment('Materialised ancestor path, /12/47/');

            $table->string('code')->comment('1.2.3 — the number the surveyor writes');
            $table->string('name');
            $table->enum('kind', ['phase', 'zone', 'level', 'element', 'package'])->default('package');
            $table->unsignedInteger('sort_order')->default(0);
            // Whether anything may be booked against this node directly. A parent that carries cost as well
            // as children makes a rollup double-count, so the leaf flag is what the budget screen reads.
            $table->boolean('is_leaf')->default(true);

            $table->timestamps();

            // Per job, because 1.2.3 means something different on every job and is the number people quote.
            $table->unique(['job_id', 'code']);
            $table->index(['job_id', 'path']);
            $table->index('parent_id');
        });

        Schema::create('construction_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('construction_locations')->cascadeOnDelete();
            // Not in §16.5's column list, added for the same reason the WBS has one: "every open item in this
            // *building*" is the question straight after "in this room", and without a path that is a
            // recursive query on the hottest screen before handover.
            $table->string('path')->nullable()->comment('Materialised ancestor path, /12/47/');

            $table->string('code')->nullable()->comment('Optional: a grid reference or a room number');
            $table->string('name');
            $table->enum('type', [
                'site', 'building', 'block', 'level', 'zone', 'room', 'grid', 'chainage', 'structure',
            ])->default('zone');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['job_id', 'path']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_locations');
        Schema::dropIfExists('construction_wbs_nodes');
    }
};
