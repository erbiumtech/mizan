<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('mprs', function (Blueprint $table) {
            $table->text('current_month_learning')->nullable()->after('next_mpr_goal');
        });
    }

    public function down()
    {
        Schema::table('mprs', function (Blueprint $table) {
            $table->dropColumn('current_month_learning');
        });
    }
};
