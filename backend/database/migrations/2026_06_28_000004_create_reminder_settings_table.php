<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user daily reading reminder configuration. Up to three local-time slots, a
 * timezone, and the channels the user wants reminders on. The scheduler resolves these
 * into due reminders; absence of a row means no reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->time('morning_at')->nullable();
            $table->time('afternoon_at')->nullable();
            $table->time('evening_at')->nullable();
            $table->string('timezone', 64)->nullable();    // falls back to the user's timezone
            $table->json('channels')->nullable();          // subset of [in_app, email, push]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_settings');
    }
};
