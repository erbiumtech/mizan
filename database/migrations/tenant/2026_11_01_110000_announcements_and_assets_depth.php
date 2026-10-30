<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Announcements and issued assets, deeper — docs/saas-launch-plan.md §14.10. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            // What kind of notice this is, and how loudly to say it. Both are a company's
            // own vocabulary, so both are option lists rather than enums.
            $table->string('category', 40)->nullable()->after('body');
            $table->string('priority', 20)->default('normal')->after('category');
            /*
             * Filed away, not deleted.
             *
             * An announcement that has run its course still has to be findable: "when did
             * we tell everybody about the new leave policy" is a question somebody asks a
             * year later. Expiring hides it from the dashboard; archiving takes it off the
             * working list and leaves it in the record.
             */
            $table->timestamp('archived_at')->nullable()->after('expires_at');
        });

        Schema::table('issued_assets', function (Blueprint $table): void {
            // The sticker on the laptop. Not the serial: a serial is the maker's and a tag
            // is the company's, and an audit counts tags.
            $table->string('tag', 40)->nullable()->after('serial_no');
            $table->string('vendor', 120)->nullable()->after('tag');
            // When the cover runs out, which is what decides whether a broken screen is a
            // phone call or a purchase.
            $table->date('warranty_until')->nullable()->after('vendor');

            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::table('issued_assets', function (Blueprint $table): void {
            $table->dropIndex(['tag']);
            $table->dropColumn(['tag', 'vendor', 'warranty_until']);
        });

        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn(['category', 'priority', 'archived_at']));
    }
};
