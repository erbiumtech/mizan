<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Brand Studio — docs/saas-launch-plan.md §14.10: ID cards and business cards. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_id_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            /*
             * The number printed on the card, and the thing a replacement changes.
             *
             * Not the employee code: somebody who loses a card is issued another, and a
             * reader checking a card against the record has to be able to tell which card
             * they are holding. Unique, so a found card can always be identified.
             */
            $table->string('serial', 24)->unique();
            $table->date('issued_on');
            $table->date('expires_on')->nullable();
            // Revoked rather than deleted: a card that was cancelled is exactly the card
            // somebody might present, and the verification page has to be able to say so.
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'revoked_at']);
        });

        Schema::create('business_card_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(100);
            $table->string('status', 12)->default('requested');
            // What the cards should say, where it differs from the record — a shorter
            // title, a direct line. Blank means "what the record says".
            $table->string('title_override')->nullable();
            $table->string('phone_override', 40)->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_card_orders');
        Schema::dropIfExists('employee_id_cards');
    }
};
