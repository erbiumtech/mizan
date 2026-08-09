<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people at a client, as opposed to the client.
 *
 * A Contact carries one email and one phone, so a company is one person. Invoices go
 * to whoever that is, and the accounts clerk who actually pays them, the manager who
 * queries a line and the director who signs the contract are all the same row or
 * nowhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable()->comment('Their role there, e.g. Accounts Payable');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Who to write to when there is one person to write to. The contact's own
            // email stays the fallback, so nothing that works today stops working.
            $table->boolean('is_primary')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_persons');
    }
};
