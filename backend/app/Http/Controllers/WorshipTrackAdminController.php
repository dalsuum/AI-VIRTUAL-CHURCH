<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\WorshipTrack;
use App\Services\MusicRecommendationService;
use App\Services\PermissionService;
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
        if (in_array($language, MusicRecommendationService::SUPPORTED_LANGUAGES, true)) {
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
        $rule = fn (array $rules) => $partial ? array_merge(['sometimes'], $rules) : $rules;

        // http(s)-only URL: blocks javascript:/data: XSS vectors.
        $urlRule = ['nullable', 'url', 'max:2048', function ($attr, $value, $fail) {
            if ($value && ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $fail("The {$attr} must be an http or https URL.");
            }
        }];

        return $request->validate([
            'title'            => $rule(['required', 'string', 'max:255']),
            'artist'           => $rule(['nullable', 'string', 'max:255']),
            'language'         => $rule(['required', Rule::in(MusicRecommendationService::SUPPORTED_LANGUAGES)]),
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
        ]);
    }
}
