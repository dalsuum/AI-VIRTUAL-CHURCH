<?php

namespace App\Jobs;

use App\Models\ServiceSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * Bridges Laravel -> Python. Rather than sharing a queue serializer between two
 * languages, we publish a plain JSON job description onto a Redis list that the
 * Celery workers consume. Keeps the contract language-agnostic.
 */
class DispatchServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $sessionId) {}

    public function handle(): void
    {
        $session = ServiceSession::with('intake')->findOrFail($this->sessionId);
        $intake  = $session->intake;

        $payload = json_encode([
            'session_id'   => $session->id,
            'session_token'=> $session->session_token,
            'music_source' => $session->music_source, // 'suno' | 'youtube'
            'mood'         => $intake->mood,
            'prayer_text'  => $intake->prayer_text,
            'user_name'    => $session->user->name,
            // Registered worshippers (real email) get a personalized welcome-back
            // greeting; guests use a throwaway @guest.local address and skip it.
            'is_registered'=> ! str_ends_with((string) $session->user->email, '@guest.local'),
        ]);

        // The Python orchestrator (tasks.orchestrate) BLPOPs this list.
        Redis::rpush('ai:intake', $payload);
    }
}
