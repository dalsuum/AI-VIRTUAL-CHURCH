<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email-verification / account-activation state.
 *
 * `status` is the account lifecycle gate (distinct from billing `subscription_status`):
 * a freshly registered user is `pending` and cannot log in until they click the
 * activation link, at which point they become `active`. The activation token is stored
 * hashed (sha256) with a 24h expiry, mirroring the existing password-reset pattern, so
 * the raw token never lives in the database.
 *
 * Existing users and walk-up guests default to `active` so nothing already provisioned
 * is locked out by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('is_blocked');
            $table->string('activation_token', 64)->nullable()->after('status');
            $table->timestamp('activation_expires_at')->nullable()->after('activation_token');
            $table->index('status');
        });

        // Belt-and-braces: ensure every pre-existing row is explicitly active (the
        // column default covers new rows, but make the backfill intent unmistakable).
        DB::table('users')->whereNull('status')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'activation_token', 'activation_expires_at']);
        });
    }
};
