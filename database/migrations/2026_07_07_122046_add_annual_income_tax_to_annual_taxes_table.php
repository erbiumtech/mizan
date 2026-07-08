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
    Schema::table('annual_taxes', function (Blueprint $table) {
        $table->decimal('annual_income_tax', 12, 2)->default(0)->after('total_annual_income');
    });
}

public function down()
{
    Schema::table('annual_taxes', function (Blueprint $table) {
        $table->dropColumn('annual_income_tax');
    });
}
};
