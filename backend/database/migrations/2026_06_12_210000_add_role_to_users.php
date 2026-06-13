<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'guest'     — anonymous walk-up (@guest.local); read-only service access
            // 'member'    — registered user; service + voice studio + account settings
            // 'presenter' — member + featured presenter badge (admin-assigned)
            // 'moderator' — approve testimonies + view prayer requests (limited admin)
            // 'admin'     — full admin console
            $table->string('role', 20)->nullable()->after('is_admin');
        });

        // Backfill from the existing is_admin boolean.
        DB::statement("UPDATE users SET role = 'admin' WHERE is_admin = 1");
        DB::statement("UPDATE users SET role = 'guest' WHERE role IS NULL AND email LIKE '%@guest.local'");
        DB::statement("UPDATE users SET role = 'member' WHERE role IS NULL");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
