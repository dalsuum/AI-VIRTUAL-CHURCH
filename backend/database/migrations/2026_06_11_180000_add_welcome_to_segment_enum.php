<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE service_assets MODIFY COLUMN segment ENUM(
            'welcome', 'worship', 'opening_prayer', 'scripture', 'sermon',
            'testimony', 'offering', 'closing_hymn', 'benediction'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE service_assets MODIFY COLUMN segment ENUM(
            'worship', 'opening_prayer', 'scripture', 'sermon',
            'testimony', 'offering', 'closing_hymn', 'benediction'
        ) NOT NULL");
    }
};
