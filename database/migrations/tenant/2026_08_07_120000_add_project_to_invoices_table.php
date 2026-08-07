<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // GnuCash calls this a "job": the engagement an invoice belongs to,
            // so a client with four pieces of work running can be asked what each
            // one has been billed rather than only what the client owes.
            //
            // A real foreign key, not a soft reference: both tables live in the
            // same tenant database, and every tenant gets every migration whether
            // or not it has licensed Projects — so the target is always there.
            // Licensing decides whether the field is *offered*, not whether the
            // column exists.
            $table->foreignId('project_id')->nullable()->after('contact_id')
                ->constrained('projects')->nullOnDelete()
                ->comment('Optional: the engagement this invoice belongs to');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
