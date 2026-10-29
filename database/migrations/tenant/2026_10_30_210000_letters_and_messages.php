<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letter templates, the request queue, and the message log — docs/saas-launch-plan.md §14.7.
 *
 * Email templates are not here: they have existed since August and already carry a
 * company's wording for the mails it sends. §14.7 asks for coverage of the rest of
 * them, a preview and a test send, which is code rather than schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 40);
            $table->string('locale', 5)->default('en');
            $table->string('name', 120);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kind', 'locale']);
        });

        Schema::create('letter_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('purpose')->nullable();
            $table->string('status', 12)->default('pending');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('message_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 12);
            $table->string('recipient');
            $table->string('purpose')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 12)->default('sent');
            $table->string('reference')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['channel', 'sent_at']);
            $table->index('recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('letter_requests');
        Schema::dropIfExists('letter_templates');
    }
};
