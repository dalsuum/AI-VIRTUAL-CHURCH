<?php

namespace App\Http\Controllers;

use App\Services\MoodExpansionService;
use App\Services\MusicRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public AI Worship Radio endpoints. No auth (mirrors the public /songs panel):
 * worshippers state a mood and receive a continuously refreshable, mood-matched
 * playlist. All selection happens server-side in MusicRecommendationService;
 * the client only supplies a language, a mood, and the ids it has recently
 * played (for the no-repeat window).
 */
class MusicController extends Controller
{
    public function __construct(
        private MusicRecommendationService $recommender,
        private MoodExpansionService $moods,
        private \App\Services\HistoryService $history,
    ) {}

    /** Mood selector options (label + emoji) for the front Worship page. */
    public function moods(): JsonResponse
    {
        $moods = array_map(fn ($key) => [
            'key'    => $key,
            'label'  => $this->moods->labels($key)['en'],
            'labels' => $this->moods->labels($key),   // {en, my, td} for the language switcher
            'emoji'  => $this->moods->emoji($key),
        ], $this->moods->moodKeys());

        return response()->json(['moods' => $moods]);
    }

    /** Generate a playlist for a mood + language, excluding recently played ids. */
    public function recommend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'language'      => ['required', Rule::in(MusicRecommendationService::supportedLanguages())],
            'mood'          => ['required', 'string', 'max:100'],
            'playlist_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'exclude'       => ['nullable', 'array', 'max:50'],
            'exclude.*'     => ['integer'],
        ]);

        $result = $this->recommender->recommend(
            $data['language'],
            $data['mood'],
            $data['playlist_size'] ?? null,
            $data['exclude'] ?? [],
        );

        // Record into unified history for signed-in worshippers (route is public, so
        // the user is resolved softly). Sessions are grouped per day + mood + language.
        // Best-effort: a mirror failure must never deny the worshipper their playlist.
        try {
            $this->recordHistory($request, $data['language'], $data['mood'], $result['playlist']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Worship history mirror failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'playlist' => array_map([$this, 'present'], $result['playlist']),
            'reason'   => $result['reason'],
            'themes'   => $result['themes'],
        ]);
    }

    /** Upsert today's worship session for this user+mood and append the playlist. */
    private function recordHistory(Request $request, string $language, string $mood, array $playlist): void
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            return;
        }

        $songIds = array_values(array_filter(array_map(fn ($s) => $s['id'] ?? null, $playlist)));

        $session = \App\Models\ChatSession::forUser((int) $user->id)
            ->where('session_type', 'music')
            ->where('mood', $mood)
            ->where('language', $language)
            ->whereDate('started_at', now()->toDateString())
            ->first();

        if (! $session) {
            $session = $this->history->startSession($user, 'music', [
                'language' => $language,
                'mood'     => $mood,
                'title'    => ucwords($mood) . ' Worship',
            ]);
            \App\Models\MusicSessionMeta::create([
                'chat_session_id' => $session->id,
                'playlist'        => $songIds,
                'songs_played'    => $songIds,
            ]);
        } else {
            $meta = \App\Models\MusicSessionMeta::firstOrCreate(['chat_session_id' => $session->id]);
            $played = array_values(array_unique(array_merge($meta->songs_played ?? [], $songIds)));
            $meta->update(['playlist' => $songIds, 'songs_played' => $played]);
            $this->history->touch($session);
        }

        // Phase 2 (SessionStateStore): record the playlist event as a graph node and
        // snapshot playback state as a checkpoint. Best-effort — never abort the response.
        try {
            $this->history->recordEvent($session, 'playlist_recommended', [
                'mood'     => $mood,
                'language' => $language,
                'track_ids' => $songIds,
            ]);
            $this->history->checkpoint($session, [
                'queue'    => $songIds,
                'track_id' => $songIds[0] ?? null,
                'position' => 0,
                'shuffle'  => false,
                'volume'   => null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('music history node/checkpoint failed', ['e' => $e->getMessage()]);
        }
    }

    /** Public-safe track shape for the player (no internal columns leaked). */
    private function present($track): array
    {
        return [
            'id'              => $track->id,
            'title'           => $track->title,
            'artist'          => $track->artist,
            'language'        => $track->language,
            'genre'           => $track->genre,
            'duration'        => $track->duration,
            'youtube_url'     => $track->youtube_url,
            'spotify_url'     => $track->spotify_url,
            'apple_music_url' => $track->apple_music_url,
            'cover_image'     => $track->cover_image,
            'themes'          => $track->themes,
        ];
    }
}
