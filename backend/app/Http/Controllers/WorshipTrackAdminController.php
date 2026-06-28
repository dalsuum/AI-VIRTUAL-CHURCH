<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\WorshipTrack;
use App\Services\MoodExpansionService;
use App\Services\MusicRecommendationService;
use App\Services\PermissionService;
use App\Services\YoutubeSongSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for the worship-track recommendation catalog plus the playlist
 * settings (min/max size). Every method is gated by the `music.manage`
 * permission, mirroring SongController + `lyrics.manage`.
 *
 * SECURITY: streaming/cover URLs are validated with an http(s) scheme allowlist
 * to block javascript:/data: payloads (XSS) before they reach the front player.
 */
class WorshipTrackAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $q = WorshipTrack::query()->orderBy('title');

        $language = trim((string) $request->query('language', ''));
        if (in_array($language, MusicRecommendationService::supportedLanguages(), true)) {
            $q->where('language', $language);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$search}%")
                ->orWhere('artist', 'like', "%{$search}%"));
        }

        return response()->json(['tracks' => $q->get()]);
    }

    public function show(Request $request, WorshipTrack $worshipTrack): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        return response()->json(['track' => $worshipTrack]);
    }

    public function store(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $track = WorshipTrack::create($this->validated($request));

        return response()->json(['ok' => true, 'track' => $track], 201);
    }

    public function update(Request $request, WorshipTrack $worshipTrack): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $worshipTrack->fill($this->validated($request, true));
        $worshipTrack->save();

        return response()->json(['ok' => true, 'track' => $worshipTrack->fresh()]);
    }

    public function destroy(Request $request, WorshipTrack $worshipTrack): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $worshipTrack->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Search YouTube for embeddable worship uploads, screened through the same
     * content filter the sermon pipeline uses. Returns clean candidates the
     * admin can attach to a track.
     */
    public function youtubeSearch(Request $request, YoutubeSongSearchService $yt): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
        ]);

        if (! $yt->isConfigured()) {
            return response()->json(['ok' => false, 'message' => 'YouTube search is not configured (set YOUTUBE_API_KEY).'], 503);
        }

        return response()->json(['ok' => true, 'results' => $yt->search($data['q'])]);
    }

    /** Read the playlist-size + mood-dictionary settings. */
    public function settings(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        return response()->json([
            'min_playlist'    => (int) Setting::get('music.min_playlist', 5),
            'max_playlist'    => (int) Setting::get('music.max_playlist', 10),
            'mood_dictionary' => Setting::get('music.mood_dictionary', ''),
        ]);
    }

    /** Write the playlist-size + optional mood-dictionary override. */
    public function updateSettings(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $data = $request->validate([
            'min_playlist'    => ['required', 'integer', 'min:1', 'max:50'],
            'max_playlist'    => ['required', 'integer', 'min:1', 'max:50', 'gte:min_playlist'],
            'mood_dictionary' => ['nullable', 'string', 'max:20000'],
        ]);

        // Reject a malformed dictionary override before it is persisted.
        if (! empty($data['mood_dictionary']) && json_decode($data['mood_dictionary'], true) === null) {
            return response()->json(['ok' => false, 'message' => 'mood_dictionary must be valid JSON.'], 422);
        }

        Setting::set('music.min_playlist', (string) $data['min_playlist']);
        Setting::set('music.max_playlist', (string) $data['max_playlist']);
        Setting::set('music.mood_dictionary', (string) ($data['mood_dictionary'] ?? ''));

        return response()->json(['ok' => true]);
    }

    /** Shared validation. On update every field is optional ('sometimes'). */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate($this->rules($partial));
    }

    /** Field rule set shared by store/update and the JSON importer. */
    private function rules(bool $partial = false): array
    {
        $rule = fn (array $rules) => $partial ? array_merge(['sometimes'], $rules) : $rules;

        // http(s)-only URL: blocks javascript:/data: XSS vectors.
        $urlRule = ['nullable', 'url', 'max:2048', function ($attr, $value, $fail) {
            if ($value && ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $fail("The {$attr} must be an http or https URL.");
            }
        }];

        return [
            'title'            => $rule(['required', 'string', 'max:255']),
            'artist'           => $rule(['nullable', 'string', 'max:255']),
            'language'         => $rule(['required', Rule::in(MusicRecommendationService::supportedLanguages())]),
            'genre'            => $rule(['nullable', 'string', 'max:100']),
            'themes'           => $rule(['nullable', 'array', 'max:50']),
            'themes.*'         => ['string', 'max:60'],
            'moods'            => $rule(['nullable', 'array', 'max:50']),
            'moods.*'          => ['string', 'max:60'],
            'scriptures'       => $rule(['nullable', 'array', 'max:50']),
            'scriptures.*'     => ['string', 'max:120'],
            'duration'         => $rule(['nullable', 'integer', 'min:0', 'max:86400']),
            'youtube_url'      => $rule($urlRule),
            'spotify_url'      => $rule($urlRule),
            'apple_music_url'  => $rule($urlRule),
            'cover_image'      => $rule($urlRule),
            'lyrics_available' => $rule(['boolean']),
            'copyright_status' => $rule(['nullable', 'string', 'max:60']),
            'popularity'       => $rule(['nullable', 'integer', 'min:0', 'max:1000000']),
            'active'           => $rule(['boolean']),
        ];
    }

    /**
     * Export the catalog (optionally one language) as a portable JSON document
     * the importer round-trips: administrators keep curated libraries under
     * version control, review diffs, and re-import into any environment.
     */
    public function export(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $q = WorshipTrack::query()->orderBy('language')->orderBy('title');

        $language = trim((string) $request->query('language', ''));
        if (in_array($language, MusicRecommendationService::supportedLanguages(), true)) {
            $q->where('language', $language);
        }

        $tracks = $q->get()->map(fn (WorshipTrack $t) => [
            'title'            => $t->title,
            'artist'           => $t->artist,
            'language'         => $t->language,
            'genre'            => $t->genre,
            'duration'         => $t->duration,
            'popularity'       => $t->popularity,
            'themes'           => $t->themes ?? [],
            'moods'            => $t->moods ?? [],
            'scriptures'       => $t->scriptures ?? [],
            'youtube'          => $t->youtube_url,
            'spotify'          => $t->spotify_url,
            'apple_music'      => $t->apple_music_url,
            'cover_image'      => $t->cover_image,
            'lyrics_available' => $t->lyrics_available,
            'active'           => $t->active,
            'license'          => $t->copyright_status,
        ]);

        return response()->json(['tracks' => $tracks]);
    }

    /**
     * Bulk-import tracks from the export schema. Each row is independently
     * validated; moods are normalized to canonical ids (so pre-collapse catalogs
     * still import); duplicates (same title+artist+youtube) are skipped, not
     * inserted twice. Invalid rows are rejected and reported, valid rows commit.
     */
    public function import(Request $request, MoodExpansionService $moods): JsonResponse
    {
        PermissionService::require($request->user(), 'music.manage');

        $data = $request->validate([
            'tracks'   => ['required', 'array', 'min:1', 'max:1000'],
            'tracks.*' => ['array'],
        ]);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($data['tracks'] as $i => $row) {
            $label = trim((string) ($row['title'] ?? '')) ?: 'untitled';

            // Accept the documented schema keys, mapping to the DB columns.
            $row['youtube_url']     = $row['youtube']     ?? $row['youtube_url']     ?? null;
            $row['spotify_url']     = $row['spotify']     ?? $row['spotify_url']     ?? null;
            $row['apple_music_url'] = $row['apple_music'] ?? $row['apple_music_url'] ?? null;
            $row['copyright_status'] = $row['license']    ?? $row['copyright_status'] ?? 'curated';

            // Normalize every mood to a canonical id; reject unknown moods.
            $badMood = null;
            $row['moods'] = collect((array) ($row['moods'] ?? []))
                ->map(function ($m) use ($moods, &$badMood) {
                    $id = $moods->canonical((string) $m);
                    if ($id === null && trim((string) $m) !== '') {
                        $badMood = $m;
                    }

                    return $id;
                })
                ->filter()->unique()->values()->all();

            if ($badMood !== null) {
                $errors[] = "Row " . ($i + 1) . " ({$label}): unknown mood '{$badMood}'.";

                continue;
            }

            $v = \Illuminate\Support\Facades\Validator::make($row, array_merge($this->rules(), [
                'artist' => ['required', 'string', 'max:255'],
            ]));

            if ($v->fails()) {
                $errors[] = "Row " . ($i + 1) . " ({$label}): " . $v->errors()->first();

                continue;
            }

            $clean = $v->validated();

            $isDuplicate = WorshipTrack::where('title', $clean['title'])
                ->where('artist', $clean['artist'])
                ->where('youtube_url', $clean['youtube_url'] ?? null)
                ->exists();

            if ($isDuplicate) {
                $skipped++;

                continue;
            }

            WorshipTrack::create($clean);
            $imported++;
        }

        return response()->json([
            'ok'       => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }
}
