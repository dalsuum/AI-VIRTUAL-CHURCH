<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for every token-balance mutation. The authoritative balance
 * lives on users.token_balance; this table explains how it got there. `amount` is
 * signed (negative = spend), `balance_after` snapshots the wallet post-mutation, and
 * `type` classifies the entry (refill / grant / spend / purchase / refund). `reference`
 * links to the thing that caused it (e.g. "study:42", "service:abc"). Written only
 * inside the same DB transaction that moves the balance — see App\Services\TokenService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');                 // signed: negative = spend
            $table->unsignedInteger('balance_after');
            $table->string('type', 24);                // refill|grant|spend|purchase|refund
            $table->string('reference', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_ledger');
    }
};
