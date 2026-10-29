<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The employee record — docs/saas-launch-plan.md §14.2.
 *
 * `is_active` keeps its meaning: `status` sits beside it and derives it.
 * Departments and designations become rows, seeded from the option lists
 * they replace; the option lists stay in step so every reader of
 * `options('employees.department')` is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->after('is_active');
            $table->string('father_name')->nullable()->after('name');
            $table->string('ntn', 20)->nullable()->after('nic');
            $table->string('nationality', 60)->nullable()->after('gender');
            $table->string('marital_status', 20)->nullable()->after('nationality');
            $table->string('emergency_contact_name')->nullable()->after('secondary_phone');
            $table->string('emergency_contact_relation', 40)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_phone', 40)->nullable()->after('emergency_contact_relation');
            $table->json('current_address')->nullable()->after('address_line_2');
            $table->json('permanent_address')->nullable()->after('current_address');
            $table->string('photo_path')->nullable()->after('nic_back');
            $table->date('probation_ends_on')->nullable()->after('date_of_joining');
            $table->date('confirmed_on')->nullable()->after('probation_ends_on');
        });

        DB::table('employees')->where('is_active', false)->update(['status' => 'resigned']);

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('code', 20)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('head_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('designations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('code', 20)->nullable();
            $table->unsignedSmallInteger('level')->nullable();
            $table->decimal('pay_grade_min', 12, 2)->nullable();
            $table->decimal('pay_grade_max', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach ([['employees.department', 'departments'], ['employees.designation', 'designations']] as [$list, $table]) {
            $rows = DB::table('option_values')->where('list', $list)->orderBy('sort')->get();
            $sort = 0;

            foreach ($rows as $row) {
                DB::table($table)->insertOrIgnore([
                    'name' => $row->label ?: $row->value,
                    'code' => null,
                    'is_active' => (bool) $row->is_active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sort++;
            }
        }

        Schema::table('employee_job_history', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('employment_type')->constrained('locations')->nullOnDelete();
            $table->string('kind', 12)->nullable()->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_job_history', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('kind');
        });

        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'status', 'father_name', 'ntn', 'nationality', 'marital_status', 'emergency_contact_name', 'emergency_contact_relation',
                'emergency_contact_phone', 'current_address', 'permanent_address', 'photo_path', 'probation_ends_on', 'confirmed_on',
            ]);
        });
    }
};
