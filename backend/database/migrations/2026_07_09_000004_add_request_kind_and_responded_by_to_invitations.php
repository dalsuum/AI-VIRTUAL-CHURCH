<?php

use App\Enums\InvitationKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Join requests (v1.3 Phase C) are the invitation flow with the roles reversed: the
 * requester is the inviter (creator), the responder is whoever can manage the target
 * group. responded_by records the human actor of every terminal transition (approver /
 * decliner / revoker; NULL = the system sweep) — the audit column complementing the
 * frozen event trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('responded_by')->nullable()->after('responded_at')
                  ->constrained('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE invitations MODIFY kind ENUM('direct', 'link', 'request') NOT NULL DEFAULT 'direct'");
    }

    public function down(): void
    {
        DB::table('invitations')->where('kind', InvitationKind::REQUEST->value)->delete();
        DB::statement("ALTER TABLE invitations MODIFY kind ENUM('direct', 'link') NOT NULL DEFAULT 'direct'");

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responded_by');
        });
    }
};
