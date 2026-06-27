<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single polymorphic invitation for every together-activity (worship, reading,
 * study, prayer, pastor chat, radio). On accept, InvitationService creates the target
 * session and links it via the nullable invitable_* morph — so a new activity is an
 * enum value + a session factory, never a new invitation table or workflow.
 *
 * UUID id (unguessable, shardable). correlation_id ties this invitation to the session,
 * notifications, audit and analytics rows it spawns. Status is mutated only by
 * InvitationService::transition().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('correlation_id');
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->enum('activity', [
                'worship', 'bible_reading', 'bible_study', 'prayer', 'pastor_chat', 'radio',
            ]);
            $table->nullableMorphs('invitable');            // the session created on accept
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled', 'expired'])
                  ->default('pending');
            $table->timestamp('scheduled_at')->nullable();  // null = immediate
            $table->string('timezone', 64)->nullable();
            $table->json('recurrence')->nullable();         // null = one-off
            $table->string('message', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['invitee_id', 'status']);        // "my pending invitations"
            $table->index(['inviter_id', 'status']);        // "invitations I sent"
            $table->index(['status', 'expires_at']);        // expiry sweep
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
