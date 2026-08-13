<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hiring: vacancies, the people who apply, and the offer that turns one into an
 * employee.
 *
 * **`applicants` is separate from `applications` because one person applies twice**, and
 * a system that cannot see that has no memory — which is the single most useful thing a
 * recruitment record does after the first year.
 *
 * `recruitment` requires nothing. An applicant is not an employee, and a company hiring
 * its first employee has no `employees` licence yet; the conversion is the guarded part.
 *
 * **Applicant data is the most sensitive this application holds, and it is held about
 * people the company never hired.** So the model is Prunable with a retention setting
 * that deletes the CV file with the row, and the help text says plainly that the company
 * is the data controller. Nothing here should quietly keep a stranger's CV for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('employment_type')->nullable();
            $table->unsignedSmallInteger('openings')->default(1);

            $table->string('status')->default('open')->comment('draft|open|on_hold|filled|closed');

            // Guarded on `employees`: a company hiring its first person has no employee
            // to name as the hiring manager.
            $table->foreignId('hiring_manager_employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();
            $table->string('currency_code', 3)->nullable();

            $table->text('description')->nullable();
            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('cnic')->nullable();
            $table->string('source')->nullable()->comment('Where they came from: referral, LinkedIn, an agency');
            $table->string('resume_path')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('current_employer')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->decimal('expected_salary', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // The dedup reads. A person applying twice should be recognised, and these
            // are what a search before creating a second row matches on.
            $table->index('email');
            $table->index('phone');
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();

            $table->string('stage')->default('applied')
                ->comment('applied|screening|interview|offer|hired|rejected|withdrawn');

            $table->date('applied_on');
            $table->string('rejected_reason')->nullable();
            $table->unsignedTinyInteger('rating')->nullable()->comment('1-5, a person\'s judgement');

            // The trail from vacancy to payroll. Set by the hire conversion, and what
            // makes "where did this employee come from" answerable years later.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            // One application per person per vacancy. Applying to a *different* vacancy
            // is a second row, which is exactly the memory this design is for.
            $table->unique(['vacancy_id', 'applicant_id']);
            $table->index('stage');
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->unsignedTinyInteger('round')->default(1);
            $table->dateTime('scheduled_at');
            $table->string('mode')->default('in_person')->comment('in_person|phone|video');
            $table->string('panel')->nullable()->comment('Free text: who is on it');
            $table->string('location')->nullable();
            $table->string('outcome')->nullable()->comment('pending|passed|failed|no_show');
            $table->text('notes')->nullable();

            $table->foreignId('interviewer_employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();

            $table->decimal('salary', 15, 2);

            // The allowances and deductions the package will carry, as the pay
            // components they will become. JSON because at offer time these are a
            // proposal, not rows anybody can point at — the EmployeeSetting is created
            // from them at acceptance.
            $table->json('components')->nullable();

            $table->date('joining_date');
            $table->string('status')->default('draft')->comment('draft|issued|accepted|declined|withdrawn');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('decline_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('vacancies');
    }
};
