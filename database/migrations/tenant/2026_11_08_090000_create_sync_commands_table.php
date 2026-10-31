<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The server inbox of the offline sync engine — docs/desktop-mobile-plan.md §5a rule 2.
 *
 * One row per command a device ever sent, keyed on the ULID the device minted for it. A command
 * that arrives twice — the connection dropped after the server applied it and before the device
 * heard — finds its row and gets its original answer back instead of being applied again. That is
 * the whole of the idempotency, and it is why the row keeps the result and not just the fact.
 *
 * `captured_at` is the device's clock and is recorded for the record; `received_at` is ours and is
 * the order that counts. Tenant-side, because a punch belongs to the company it was punched in, and
 * so the same ULID in two companies — one phone, two memberships — can never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 26)->unique();
            $table->string('workflow', 40);
            $table->foreignId('user_id')->index();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->json('payload');
            $table->dateTime('captured_at')->nullable();
            $table->dateTime('received_at');
            $table->string('status', 12)->comment('applied|rejected');
            $table->unsignedSmallInteger('http_status');
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['workflow', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_commands');
    }
};
