<?php

namespace App\Jobs;

use App\Models\MusicTrack;
use App\Models\ServiceSession;
use App\Models\Setting;
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
            'music_source' => $session->music_source, // 'hymn_sung' | 'hymn' | 'suno' | 'youtube'
            // Service language ('en' | 'my'), locked at session start like the music
            // source. Drives the LLM output language, the Bible translation (BSB vs
            // Judson 1835 Burmese), the hymn library, and the narration voice.
            'language'     => $session->language ?? 'en',
            // How spoken segments are voiced (global admin setting). 'openai' tells
            // the worker to synthesize TTS audio; 'browser'/'off' leave it to the
            // client (browser speech) or silent, so the worker skips narration.
            'narration_mode'  => Setting::get('narration_mode', 'browser'),
            // Per-language narration gate. English on by default; Myanmar and Tedim
            // off by default (no Tedim edge-tts voice; Myanmar quality may vary).
            // Admin can flip each independently via the dashboard.
            'narration_enabled' => Setting::get('narration_' . ($session->language ?? 'en'), $session->language === 'en' || !$session->language ? '1' : '0') === '1',
            'avatar_enabled'  => Setting::get('avatar_enabled', '1') === '1',
            'edge_tts_voice'  => Setting::get('edge_tts_voice', 'en-US-AriaNeural'),
            // Where generated audio is stored (local dir vs S3). null lets the worker
            // keep its own env default.
            'storage_backend'=> Setting::get('storage_backend'),
            // A song from the reusable mood pool, when we choose to reuse one instead
            // of composing a fresh track (see resolveReuseTrack). null = compose new.
            'reuse_track'  => $this->resolveReuseTrack($session, $intake),
            'mood'         => $intake->mood,
            'prayer_text'  => $intake->prayer_text,
            // Only hand the worker a name when the worshipper actually gave one.
            // Anonymous guests carry a display-only placeholder name that must never
            // appear in the spoken service, so we send null and the worker omits it.
            'presenter_gender' => $session->presenter_gender ?? 'female',
            'user_name'    => $session->user->name_provided ? $session->user->name : null,
            // Registered worshippers (real email) get a personalized welcome-back
            // greeting; guests use a throwaway @guest.local address and skip it.
            'is_registered'=> ! str_ends_with((string) $session->user->email, '@guest.local'),
        ]);

        // The Python orchestrator (tasks.orchestrate) BLPOPs this list.
        Redis::rpush('ai:intake', $payload);
    }

    /**
     * Decide whether to serve a previously composed song from the mood pool.
     *
     * Reuse only applies to AI-composed (suno) services with the pool enabled. The
     * rule: if THIS worshipper has used THIS mood in THIS language before, compose
     * a fresh song so a returning visitor never hears a repeat. If they're new to
     * the mood/language pair, hand them a random track another worshipper already
     * generated for it — instant and free.
     * Returns null (compose fresh) when reuse is off, the mood is a repeat for this
     * user, or the pool has nothing for the mood yet.
     */
    private function resolveReuseTrack(ServiceSession $session, $intake): ?array
    {
        if ($session->music_source !== 'suno' || Setting::get('music_reuse', '1') !== '1') {
            return null;
        }

        $language = $session->language ?? 'en';

        $heardMoodBefore = ServiceSession::where('user_id', $session->user_id)
            ->where('id', '!=', $session->id)
            ->where('language', $language)
            ->whereHas('intake', fn ($q) => $q->where('mood', $intake->mood))
            ->exists();
        if ($heardMoodBefore) {
            return null;
        }

        $tracks = MusicTrack::where('mood', $intake->mood)
            ->where('language', $language)
            ->whereNotNull('lyrics')
            ->where('lyrics', '!=', '')
            ->inRandomOrder()
            ->limit(20)
            ->get();

        $track = $tracks->first(fn (MusicTrack $track) => $this->lyricsMatchLanguage($track->lyrics, $language));

        return $track ? [
            'storage_key'  => $track->storage_key,   // raw object key; worker re-presigns
            'provider_ref' => $track->provider_ref,
            'language'     => $track->language,
            'title'        => $track->title,
            'lyrics'       => $track->lyrics,
        ] : null;
    }

    /** Refuse cross-language Suno reuse even when older rows were mislabeled. */
    private function lyricsMatchLanguage(?string $lyrics, string $language): bool
    {
        $text = trim((string) $lyrics);
        if ($text === '') {
            return false;
        }

        if ($language === 'my') {
            return preg_match('/[\x{1000}-\x{109F}]/u', $text) === 1;
        }

        if ($language === 'td') {
            $lower = mb_strtolower($text);

            $forbiddenHits = 0;
            foreach ([
                'pathian', 'lalpa', 'isua', 'kohhran', 'tawngtaina', 'tawngtai',
                'ka lawm e', 'halleluiah', 'i lawm e',
            ] as $word) {
                if (str_contains($lower, $word)) {
                    $forbiddenHits++;
                }
            }
            if ($forbiddenHits > 0) {
                return false;
            }

            $tedimHits = 0;
            foreach ([
                'pasian', 'topa', 'zeisu', 'krist', 'lungdamna', 'kilemna',
                'hehpihna', 'nang', 'hong', 'ka ', 'kong', 'na ', 'sungah',
                'tuni', 'nuntakna', 'lametna', 'bia', 'phat',
            ] as $word) {
                if (str_contains($lower, $word)) {
                    $tedimHits++;
                }
            }

            $coreHits = 0;
            foreach (['pasian', 'topa', 'zeisu', 'krist'] as $word) {
                if (str_contains($lower, $word)) {
                    $coreHits++;
                }
            }

            $englishHits = 0;
            foreach ([
                'lord', 'jesus', 'grace', 'mercy', 'worship', 'trust',
                'heart', 'promise', 'fear', 'peace', 'love', 'hope',
            ] as $word) {
                if (str_contains($lower, $word)) {
                    $englishHits++;
                }
            }

            return $tedimHits >= 4 && $coreHits >= 2 && $englishHits <= 1;
        }

        return true;
    }
}
