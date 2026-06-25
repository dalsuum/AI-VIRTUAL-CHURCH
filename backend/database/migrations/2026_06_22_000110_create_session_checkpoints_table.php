<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rehydratable state snapshots taken at a node. This is what makes "resume" mean
        // ONE thing across modules: Study round/engine state, Worship service milestones,
        // and Music playback position all become a checkpoint blob keyed to a node.
        // `state_blob` is encrypted at rest.
        Schema::create('session_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->uuid('node_id');
            $table->text('state_blob');                       // encrypted
            $table->timestamp('created_at')->useCurrent();

            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_checkpoints');
    }
};
