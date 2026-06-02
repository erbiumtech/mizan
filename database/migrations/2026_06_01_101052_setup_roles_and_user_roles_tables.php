<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Purana column users table sa delete karo (agar add kiya tha)
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }

        // 2. Roles Table banao
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // jaise 'admin', 'user'
            $table->timestamps();
        });

        // 3. Pivot Table (user_role) banao Many-to-Many k liye
        Schema::create('user_role', function (Blueprint $table) {
            $table->id();
            // foreignId automatically indexing aur constraints handle krti ha
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user');
        });
    }
};
