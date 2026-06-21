<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous-usage ledger for guests, so the "one free use per service" rule survives
 * cookie clearing / incognito. We never store a raw IP or fingerprint — only a salted
 * SHA-256 hash of (IP) and (browser fingerprint). A row is keyed by the pair; the
 * services it has consumed are recorded in `services_used` (JSON map of service key →
 * first-used ISO timestamp). See App\Services\GuestUsageService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('ip_hash', 64);
            $table->string('fingerprint_hash', 64)->nullable();
            // A long-lived first-party cookie (HttpOnly UUID). The third signal: IP and
            // fingerprint both drift (VPN, mobile network, browser updates); matching on
            // any one of the three makes evasion meaningfully harder. See GuestUsageService.
            $table->string('cookie_hash', 64)->nullable();
            $table->json('services_used')->nullable();
            $table->timestamps();

            $table->unique(['ip_hash', 'fingerprint_hash']);
            $table->index('ip_hash');
            $table->index('cookie_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_tracking');
    }
};
