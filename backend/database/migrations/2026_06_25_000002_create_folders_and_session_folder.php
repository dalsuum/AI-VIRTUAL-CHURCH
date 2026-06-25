<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History folders: a user-curated way to group sessions in the sidebar. A session
 * belongs to at most one folder (nullable folder_id); deleting a folder un-files its
 * sessions (nullOnDelete) rather than deleting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'position']);
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('user_id')
                ->constrained('folders')->nullOnDelete();
            $table->index(['user_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
            $table->dropIndex(['user_id', 'folder_id']);
            $table->dropColumn('folder_id');
        });
        Schema::dropIfExists('folders');
    }
};
