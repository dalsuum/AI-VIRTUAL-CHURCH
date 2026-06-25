<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Let memory attach to ANY unified session (Pastor Chat, etc.), not just
        // Bible Study. The legacy `session_id` (→ study_sessions) is kept intact.
        Schema::table('ai_memories', function (Blueprint $table) {
            $table->uuid('chat_session_id')->nullable()->after('session_id');
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->nullOnDelete();
            $table->index('chat_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_memories', function (Blueprint $table) {
            $table->dropForeign(['chat_session_id']);
            $table->dropColumn('chat_session_id');
        });
    }
};
