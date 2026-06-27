<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user visibility preferences. Read by PrivacyGate on every cross-user access.
 * Absence of a row means platform defaults (friends-visible, not incognito) — the
 * gate tolerates a missing row so we don't have to backfill existing users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('profile_visibility',  ['private', 'friends', 'church', 'public'])->default('friends');
            $table->enum('activity_visibility', ['private', 'friends', 'church', 'public'])->default('friends');
            $table->enum('presence_visibility', ['private', 'friends', 'church', 'public'])->default('friends');
            $table->boolean('friend_only_mode')->default(false); // only friends may invite/interact
            $table->boolean('incognito')->default(false);        // suppress presence/activity emission
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_settings');
    }
};
