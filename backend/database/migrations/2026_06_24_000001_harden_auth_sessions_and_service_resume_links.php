<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('auth_session_version')->default(0)->after('password');
        });

        Schema::table('service_sessions', function (Blueprint $table) {
            $table->string('resume_token_hash', 64)->nullable()->unique()->after('session_token');
            $table->timestamp('resume_token_expires_at')->nullable()->after('resume_token_hash');
            $table->timestamp('resume_token_used_at')->nullable()->after('resume_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'resume_token_hash',
                'resume_token_expires_at',
                'resume_token_used_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auth_session_version');
        });
    }
};
