<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-phase token holds. reserve() debits the wallet and opens a 'pending' row; the
 * caller then runs the (fallible) AI request and either commit()s — finalising the
 * charge — or rollback()s, refunding the wallet. If a worker dies mid-request, the
 * reservations:cleanup command rolls back rows past `expires_at` so tokens are never
 * stranded. See App\Services\TokenService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('service', 48);
            $table->string('status', 16)->default('pending'); // pending|committed|rolled_back
            $table->string('reference', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['status', 'expires_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_reservations');
    }
};
