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
            $table->boolean('enabled')->default(false);
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
