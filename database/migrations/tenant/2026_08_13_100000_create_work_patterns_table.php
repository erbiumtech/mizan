<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which days of the week a company works, and how long a working day is.
 *
 * This is the table `leave.weekend_days` was a stopgap for. HolidayCalendar
 * deliberately has no isWorkingDay() — a holiday is a date question, a weekend is a
 * *pattern* question, and nothing owned the second one until now. With `attendance`
 * licensed the leave-day generator reads an employee's pattern; without it, the
 * setting remains the answer, which is why the setting is not being removed.
 *
 * A pattern rather than a column on `employees` because companies run more than one:
 * a factory floor on six days and an office on five is the ordinary case here, not an
 * edge one. And `employee_work_patterns` is dated rather than a foreign key on the
 * employee, because somebody moving from the floor to the office in March must not
 * retrospectively have been on the office pattern in February — the same reasoning
 * that put `employee_job_history` in phase 0.
 *
 * `expected_hours` is load-bearing well beyond attendance: it is the only place this
 * system records how long a working day is, and phase 3a derives an hourly rate from
 * it to pay overtime. A wrong figure here is a wrong overtime rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('e.g. "Office, Mon-Fri" or "Factory, six days"');

            // Exactly one default, enforced in the model rather than here: "at most
            // one row true" is not a portable column constraint, and the failure it
            // guards against — two defaults, so which one a new employee gets depends
            // on insertion order — is silent.
            $table->boolean('is_default')->default(false);

            $table->unsignedTinyInteger('week_start')->default(1)
                ->comment('ISO-8601 weekday the week starts on: 1 = Monday, 7 = Sunday');

            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('work_pattern_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_pattern_id')->constrained('work_patterns')->cascadeOnDelete();

            $table->unsignedTinyInteger('weekday')->comment('ISO-8601: 1 = Monday … 7 = Sunday');

            $table->boolean('is_working')->default(true);

            // Nullable rather than 0 on a non-working day: "we do not work Sunday" and
            // "Sunday is a zero-hour working day" are different statements, and the
            // hourly rate in phase 3a divides by this.
            $table->decimal('expected_hours', 4, 2)->nullable()
                ->comment('How long this day is. The only place the system records that — phase 3a divides by it');

            // Indicative, for a late-arrival figure. Deliberately not a lock: this
            // application does not police when somebody arrives, it records it.
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->timestamps();

            // One row per weekday per pattern. Two rows for Tuesday makes "is Tuesday
            // a working day" depend on which is read first.
            $table->unique(['work_pattern_id', 'weekday']);
        });

        Schema::create('employee_work_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('work_pattern_id')->constrained('work_patterns')->restrictOnDelete();

            $table->date('from_date');

            // Open-ended until somebody moves off it. Nullable rather than a far-future
            // date so "still on this pattern" is a fact rather than a convention nobody
            // remembers.
            $table->date('to_date')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_patterns');
        Schema::dropIfExists('work_pattern_days');
        Schema::dropIfExists('work_patterns');
    }
};
