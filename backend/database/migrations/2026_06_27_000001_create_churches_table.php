<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant root for the community platform. The platform ships single-church today,
 * so every church-scoped table carries a NULLABLE church_id that backfills to a
 * default church. Multi-tenant enforcement (global scopes) is deferred to Phase 6 —
 * this table only makes the schema tenant-READY without changing current behavior.
 *
 * parent_id models the church → campus → community hierarchy without a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('churches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('churches')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone', 64)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('churches');
    }
};
