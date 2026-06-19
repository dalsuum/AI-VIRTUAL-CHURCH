<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ad_impressions.location enum predates the 'special_day' and 'sticker_ads'
 * ad slots, so impressions for those locations were silently truncated (MySQL
 * 1265) and never recorded. Widen the enum to match the controller's accepted
 * locations so traffic on the Special Day MV + Live Sticker ad slots tracks.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE ad_impressions MODIFY COLUMN location "
            . "ENUM('start','between','end','special_day','sticker_ads') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE ad_impressions MODIFY COLUMN location "
            . "ENUM('start','between','end') NOT NULL"
        );
    }
};
