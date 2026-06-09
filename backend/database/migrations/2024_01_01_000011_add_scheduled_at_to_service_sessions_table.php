<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sessions', function (Blueprint $table) {
            // When set and in the future, the pipeline is held until this moment;
            // a scheduler dispatches it then. Null = generate immediately (default).
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->index('scheduled_at');
        });

        // Add a 'scheduled' status for services awaiting their dispatch time.
        DB::statement(
            "ALTER TABLE service_sessions MODIFY COLUMN status "
            . "ENUM('initializing','scheduled','active','completed','abandoned') "
            . "NOT NULL DEFAULT 'initializing'"
        );
    }

    public function down(): void
    {
        DB::statement("UPDATE service_sessions SET status = 'initializing' WHERE status = 'scheduled'");
        DB::statement(
            "ALTER TABLE service_sessions MODIFY COLUMN status "
            . "ENUM('initializing','active','completed','abandoned') "
            . "NOT NULL DEFAULT 'initializing'"
        );

        Schema::table('service_sessions', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};
