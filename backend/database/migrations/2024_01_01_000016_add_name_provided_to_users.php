<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Did the worshipper actually give us their name? Registered users always do;
    // guests may stay anonymous, in which case we assign a friendly visitor name for
    // display but must NOT use it in the spoken service (prayer/sermon/benediction).
    // Defaults to true so existing rows and registrations are treated as named.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('name_provided')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name_provided');
        });
    }
};
