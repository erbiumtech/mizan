<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('bank_id')->nullable()->after('nic')
                ->constrained('banks')->nullOnDelete();
            // Payee Address1 / Address2 columns of the iPayments bank file.
            $table->string('address_line_1')->nullable()->after('iban_no');
            $table->string('address_line_2')->nullable()->after('address_line_1');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_id');
            $table->dropColumn(['address_line_1', 'address_line_2']);
        });
    }
};
