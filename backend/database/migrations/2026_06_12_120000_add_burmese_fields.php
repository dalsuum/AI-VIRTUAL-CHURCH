<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Burmese (Myanmar) localization columns.
 *
 * service_assets.burmese_text  — Burmese translation of text_payload, filled by
 *     LocalizeServiceToBurmese job after the service assembles. Myanmar Unicode
 *     only (never Zawgyi) to match edge-tts my-MM voices and the frontend fonts.
 *
 * service_sessions.burmese_status — tracks the localization pipeline stage:
 *     null      = not a Burmese session (or localization not yet dispatched)
 *     'pending' = job queued but not started
 *     'ready'   = all assets have burmese_text
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->longText('burmese_text')->nullable()->after('tedim_text');
        });

        Schema::table('service_sessions', function (Blueprint $table) {
            $table->string('burmese_status', 20)->nullable()->after('tedim_status');
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->dropColumn('burmese_text');
        });

        Schema::table('service_sessions', function (Blueprint $table) {
            $table->dropColumn('burmese_status');
        });
    }
};
