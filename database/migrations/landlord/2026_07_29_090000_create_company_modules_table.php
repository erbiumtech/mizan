<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company module licensing and activation. Landlord-side on purpose: reads
 * must work from commands and queued jobs, where no tenant is current.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->boolean('licensed')->default(false);

            // Nullable on purpose — three states, not two. NULL means the company
            // has never made a choice, which is different from having chosen off:
            //
            //   NULL  -> a licence grant switches the module on, so a company does
            //            not have to go and enable something it just paid for;
            //   false -> the company switched it off itself, and that survives a
            //            licence being revoked and re-granted.
            //
            // With a plain boolean the two are indistinguishable and one of the
            // two behaviours has to be wrong.
            $table->boolean('enabled')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'module']);
        });

        $this->backfillExistingCompanies();
    }

    public function down(): void
    {
        Schema::dropIfExists('company_modules');
    }

    /**
     * Every company that exists *before* this feature ships keeps everything it
     * had: all modules licensed and enabled. Nobody loses a feature on upgrade
     * day, and revoking a licence stays a deliberate per-company act.
     *
     * Companies created afterwards get Core only — see CompanyProvisioner.
     */
    private function backfillExistingCompanies(): void
    {
        $companies = DB::table('companies')->pluck('id');

        if ($companies->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($companies as $companyId) {
            foreach (array_keys(config('modules', [])) as $module) {
                $rows[] = [
                    'company_id' => $companyId,
                    'module' => $module,
                    'licensed' => true,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('company_modules')->insert($chunk);
        }
    }
};
