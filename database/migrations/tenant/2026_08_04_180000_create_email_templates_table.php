<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words in the emails, as something a company can change.
 *
 * Notification text is in PHP: "Please open it and confirm the figures are right"
 * reaches every employee of every company in exactly those words, and changing them
 * for one company means a deployment. It matters as soon as somebody outside the
 * building reads one.
 *
 * A template is an override, not a requirement. Where a company has not written one,
 * the notification's own text is used — so this adds a way to change the wording
 * without making anybody supply it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();

            // The notification it overrides, e.g. payslip_issued.
            $table->string('key')->unique();

            $table->string('subject')->nullable();
            $table->text('greeting')->nullable();
            $table->text('body')->nullable()->comment('One paragraph per line');
            $table->string('action_label')->nullable();
            $table->text('closing')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
