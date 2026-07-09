<?php

use App\Enums\InvitationKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the ONE invitation model with an open, shareable kind (v1.3): a link
 * invitation has no addressee (invitee_id NULL), carries an unguessable token, and
 * may be redeemed multiple times up to max_uses. Direct invitations are untouched —
 * existing rows keep kind=direct. group_membership joins the activity enum as the
 * thing a link invites you INTO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->enum('kind', ['direct', 'link'])->default('direct')->after('invitee_id');
            $table->string('token', 64)->nullable()->unique()->after('kind');
            $table->unsignedSmallInteger('max_uses')->nullable()->after('token');   // null = unlimited
            $table->unsignedInteger('use_count')->default(0)->after('max_uses');
        });

        // Links have no addressee; the FK stays for direct invitations.
        DB::statement('ALTER TABLE invitations MODIFY invitee_id BIGINT UNSIGNED NULL');
        DB::statement("ALTER TABLE invitations MODIFY activity ENUM('worship', 'bible_reading', 'bible_study', 'prayer', 'pastor_chat', 'radio', 'group_membership') NOT NULL");
    }

    public function down(): void
    {
        DB::table('invitations')->where('kind', InvitationKind::LINK->value)->delete();
        DB::statement("ALTER TABLE invitations MODIFY activity ENUM('worship', 'bible_reading', 'bible_study', 'prayer', 'pastor_chat', 'radio') NOT NULL");
        DB::statement('ALTER TABLE invitations MODIFY invitee_id BIGINT UNSIGNED NOT NULL');

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['kind', 'token', 'max_uses', 'use_count']);
        });
    }
};
