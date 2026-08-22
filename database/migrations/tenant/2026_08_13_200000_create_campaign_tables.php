<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: campaigns. **Last, and the only module here that can damage the company's
 * reputation.**
 *
 * Two hard constraints, both external, both from docs/crms-plan.md §6:
 *
 * **WhatsApp is template-gated.** Meta's Cloud API only permits pre-approved template
 * messages outside a 24-hour customer-service window. A campaign that ignores this fails at
 * the API, and repeated attempts risk the number. So the WhatsApp channel sends approved
 * templates with variables, never free text — enforced in CampaignSender, not merely
 * documented.
 *
 * **Consent is a row, not a checkbox.** `consents` records channel, state, source and when,
 * because "who agreed to this and when" is the only defensible answer when somebody
 * complains, and an `is_subscribed` boolean cannot answer it. Every send checks it; an
 * unsubscribe writes a NEW row rather than flipping the old one, so the history of somebody
 * opting in, out and in again survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // A stored definition rather than a saved SQL string: the audience is re-resolved
            // when a campaign is sent, so a segment means "everybody who matches now" rather
            // than a frozen list that silently goes stale.
            $table->json('definition')->nullable()
                ->comment('Structured filters, resolved at send time — never a stored SQL string');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel')->comment('email|whatsapp');

            $table->string('subject')->nullable()->comment('Email only');

            // For WhatsApp this is the approved TEMPLATE NAME, not the message. Free text
            // cannot be sent on that channel outside the service window, and pretending
            // otherwise fails at the API.
            $table->string('template_name')->nullable()
                ->comment('WhatsApp: the pre-approved template. Required for that channel.');

            $table->text('body')->nullable()->comment('Email body, or template variables as text');

            $table->foreignId('segment_id')->nullable()->constrained('segments')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->string('status')->default('draft')
                ->comment('draft|scheduled|sending|sent|cancelled');

            $table->foreignId('created_by')->nullable()->index();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();

            // Either kind of recipient, and exactly one — the same shape opportunities use,
            // because a campaign goes to prospects and customers alike.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('channel');
            $table->string('to')->nullable()->comment('The address or number actually used');

            $table->string('status')->default('pending')
                ->comment('pending|sent|failed|skipped_no_consent');

            $table->timestamp('sent_at')->nullable();
            $table->string('failed_reason')->nullable();

            $table->timestamps();

            // One send per recipient per campaign: the guard against a retry double-sending,
            // which on WhatsApp risks the number rather than merely annoying somebody.
            $table->unique(['campaign_id', 'contact_id'], 'campaign_sends_unique_contact');
            $table->unique(['campaign_id', 'lead_id'], 'campaign_sends_unique_lead');

            $table->index(['campaign_id', 'status']);
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();

            // Polymorphic over Lead and Contact, stored as the ModuleMap alias.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('channel')->comment('email|whatsapp');
            $table->string('state')->comment('granted|revoked');

            // Where the consent came from — a form, a phone call, an import. The part that
            // makes it defensible: "they agreed" is worth nothing without "and here is how".
            $table->string('source')->nullable();

            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()->index();
            $table->timestamps();

            // **No unique key on (subject, channel) — deliberately.** Every grant and every
            // revocation is its own row, so somebody opting in, out and in again leaves three
            // rows rather than one flipped flag. The current state is the latest row, and the
            // history is the answer to a complaint.
            $table->index(['subject_type', 'subject_id', 'channel', 'recorded_at'], 'consents_current_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('campaign_sends');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('segments');
    }
};
