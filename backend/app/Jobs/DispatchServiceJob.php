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
        $language = $session->language ?? 'en';

        $payload = json_encode([
            'session_id'   => $session->id,
            'session_token'=> $session->session_token,
            'music_source' => $session->music_source, // 'hymn_sung' | 'hymn' | 'suno' | 'youtube'
            // Service language ('en' | 'my' | 'td'), locked at session start like the music
            // source. Drives the LLM output language, the Bible translation (BSB vs
            // Judson 1835 Burmese), the hymn library, and the narration voice.
            'language'     => $language,
            // How spoken segments are voiced — per-language admin setting.
            // Defaults are server-side so the player has audio controls:
            // English uses Edge TTS; Myanmar/Tedim use local MMS-TTS.
            'narration_mode'    => Setting::narrationMode($language),
            'voicebox_engine'  => Setting::get('voicebox_engine', 'qwen'),
            // Mode encodes provider choice; this toggle suppresses server TTS entirely.
            'narration_enabled' => Setting::narrationEnabled($language),
            'avatar_enabled'  => Setting::get('avatar_enabled', '1') === '1',
            // Self-hosted open-source avatar engine (lip-syncs to the narration audio).
            // When on alongside avatar_enabled the worker prefers local over D-ID.
            'local_avatar_enabled' => Setting::get('local_avatar_enabled', '0') === '1',
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
            // Past-service data for registered users. The LLM uses this to avoid
            // repeating the same scripture passages, prayer themes, or sermon angles
            // for someone who has attended before.
            'user_history' => $this->buildUserHistory($session),
            // When the service falls inside a special-Sunday window (Mother's Day,
            // Easter, Pentecost…), bias sermon + worship toward the observance. The
            // worker filters the sermon theme by `sermon_tags` and the hymn/worship
            // mood by `music_moods`. null outside any window — normal selection.
            'special_sunday' => $this->resolveSpecialSunday($language),
        ]);

        // The Python orchestrator (tasks.orchestrate) BLPOPs this list.
        Redis::rpush('ai:intake', $payload);
    }

    /**
     * Resolve the active special Sunday (if any) for this service's language at
     * dispatch time. Returns the worker-facing bias payload, or null when no
     * observance window is open. Resolution is cheap and never blocks dispatch.
     */
    private function resolveSpecialSunday(string $language): ?array
    {
        try {
            return app(\App\Services\SpecialSundayResolver::class)->currentPayload($language);
        } catch (\Throwable $e) {
            // A catalog/DB hiccup must never stop a service from going out.
            report($e);

            return null;
        }
    }

    /**
     * Collect the worshipper's service history for the current language so the LLM
     * can avoid repeating scriptures, prayer themes, and sermon angles.
     * Guests (throwaway @guest.local accounts) have no meaningful history.
     */
    private function buildUserHistory(ServiceSession $session): array
    {
        if (str_ends_with((string) $session->user->email, '@guest.local')) {
            return [];
        }

        $past = ServiceSession::where('user_id', $session->user_id)
            ->where('id', '!=', $session->id)
            ->where('language', $session->language ?? 'en')
            ->whereHas('intake', fn ($q) => $q->whereNotNull('scripture_ref'))
            ->with(['intake:session_id,mood,custom_mood,prayer_text,scripture_ref'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $scriptureRefs = $past
            ->map(fn ($s) => $s->intake?->scripture_ref)
            ->filter()->unique()->values()->toArray();

        $pastMoods = $past
            ->map(fn ($s) => $s->intake?->mood)
            ->filter()->unique()->values()->toArray();

        // Short excerpts from past prayer text — enough for the LLM to recognise
        // recurring themes without sending the full personal text.
        $prayerExcerpts = $past
            ->map(function ($s) {
                $text = trim((string) ($s->intake?->prayer_text ?? ''));
                return $text !== '' ? mb_substr($text, 0, 80) : null;
            })
            ->filter()->unique()->values()->toArray();

        // YouTube sermon video IDs the worshipper has already been shown — passed to
        // the worker so find_sermon_video can skip them and pick a different video.
        $pastVideoIds = \App\Models\ServiceAsset::whereIn('session_id', $past->pluck('id'))
            ->where('segment', 'sermon')
            ->where('asset_type', 'youtube')
            ->whereNotNull('provider_ref')
            ->pluck('provider_ref')
            ->unique()->values()->toArray();

        return [
            'past_scripture_refs'  => $scriptureRefs,
            'past_moods'           => $pastMoods,
            'past_prayer_excerpts' => $prayerExcerpts,
            'past_video_ids'       => $pastVideoIds,
        ];
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
        if (! in_array($session->music_source, ['suno', 'musicgen'], true) || Setting::get('music_reuse', '1') !== '1') {
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

        $query = MusicTrack::where('mood', $intake->mood)
            ->where('language', $language)
            ->whereNotNull('lyrics')
            ->where('lyrics', '!=', '');

        // Prevent cross-pollination between Suno (3-min vocal) and MusicGen 
        // (30-sec instrumental) by filtering on the known provider_ref prefix.
        if ($session->music_source === 'musicgen') {
            $query->where('provider_ref', 'like', 'musicgen:%');
        } else {
            $query->where('provider_ref', 'not like', 'musicgen:%');
        }

        $tracks = $query->inRandomOrder()->limit(20)->get();

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

            // Tedim declarative sentences end with "hi"; benedictive endings use "hen".
            // These are the most reliable Tedim-specific markers, matching the Python
            // guard in llm_engine.py.
            $terminalTedim = substr_count($lower, " hi\n")
                + substr_count($lower, " hi.")
                + substr_count($lower, " hi!")
                + substr_count($lower, " hen\n")
                + substr_count($lower, " hen.")
                + substr_count($lower, " hen!");

            if (str_ends_with(rtrim($lower), ' hi') || str_ends_with(rtrim($lower), ' hen')) {
                $terminalTedim++;
            }

            // The lyric body must clearly be Tedim/Zolai.
            return $tedimHits >= 5 && $coreHits >= 2 && $englishHits === 0 && $terminalTedim >= 2;
        }

        return true;
    }
}
