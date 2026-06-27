<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One canonical row per unordered user pair. To make the pair unique regardless of
 * who initiated, we always store user_id = min(a,b) and friend_id = max(a,b), and
 * record direction/ownership separately:
 *   - requested_by : who sent the pending friend request
 *   - blocked_by   : who issued the block (when status = blocked)
 *   - favorited_by : JSON of user ids who favorited the other (favorite is one-sided)
 *
 * FriendshipService owns all writes; this migration only defines the shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();   // min(a,b)
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete(); // max(a,b)
            $table->enum('status', ['pending', 'accepted', 'blocked'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('favorited_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'friend_id']);   // at most one row per pair
            $table->index(['friend_id', 'status']);      // reverse lookups
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
