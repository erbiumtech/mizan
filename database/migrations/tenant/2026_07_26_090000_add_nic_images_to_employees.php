<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nic_front')->nullable()->after('nic'); // uploaded NIC front image path
            $table->string('nic_back')->nullable()->after('nic_front'); // uploaded NIC back image path
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['nic_front', 'nic_back']);
        });
    }
};
