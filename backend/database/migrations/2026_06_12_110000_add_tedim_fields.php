<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tedim localization columns.
 *
 * service_assets.tedim_text  — Tedim translation of text_payload, filled by
 *     LocalizeServiceToTedim job after the English service assembles.
 *
 * service_sessions.tedim_status — tracks the localization pipeline stage:
 *     null      = not a Tedim session (or localization not yet dispatched)
 *     'pending' = job queued but not started
 *     'ready'   = all assets have tedim_text
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->longText('tedim_text')->nullable()->after('text_payload');
        });

        Schema::table('service_sessions', function (Blueprint $table) {
            $table->string('tedim_status', 20)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->dropColumn('tedim_text');
        });

        Schema::table('service_sessions', function (Blueprint $table) {
            $table->dropColumn('tedim_status');
        });
    }
};
