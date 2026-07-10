<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group service (v1.4): a leader shares one of their generated services with a
 * ministry group — members open the SAME service, each at their own pace. One
 * nullable ownership flag; the service pipeline itself is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sessions', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('user_id')
                  ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
