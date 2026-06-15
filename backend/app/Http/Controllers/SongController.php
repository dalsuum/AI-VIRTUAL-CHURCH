<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Services\PermissionService;
use App\Services\SongCorpusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for the user-managed worship song library. Public reads feed the front
 * song panel; writes sit behind the admin `lyrics.manage` permission and are
 * driven by the admin console Lyrics tab (ChordPro editor).
 */
class SongController extends Controller
{
    /** Languages a song may belong to (mirrors the songs.language enum). */
    private const LANGUAGES = ['my', 'td'];

    /**
     * Public list for the front panel. Optional ?language=my|td and ?search=…
     * No auth — only published library content is exposed.
     */
    public function index(Request $request): JsonResponse
    {
        $q = Song::query()->orderBy('title');

        $language = trim((string) $request->query('language', ''));
        if (in_array($language, self::LANGUAGES, true)) {
            $q->where('language', $language);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(function ($sub) use ($search) {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('artist', 'like', "%{$search}%")
                    ->orWhere('lyrics', 'like', "%{$search}%");
            });
        }

        $songs = $q->get(['id', 'language', 'title', 'artist', 'category', 'lyrics', 'has_chords', 'source', 'url']);

        return response()->json(['songs' => $songs]);
    }

    /** Single song (used by the admin editor when opening a record). */
    public function show(Request $request, Song $song): JsonResponse
    {
        PermissionService::require($request->user(), 'lyrics.manage');

        return response()->json(['song' => $song]);
    }

    public function store(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'lyrics.manage');

        $data = $this->validated($request);
        $song = Song::create($data);
        $this->syncCorpus();

        return response()->json(['ok' => true, 'song' => $song], 201);
    }

    public function update(Request $request, Song $song): JsonResponse
    {
        PermissionService::require($request->user(), 'lyrics.manage');

        $song->fill($this->validated($request, true));
        $song->save();
        $this->syncCorpus();

        return response()->json(['ok' => true, 'song' => $song->fresh()]);
    }

    public function destroy(Request $request, Song $song): JsonResponse
    {
        PermissionService::require($request->user(), 'lyrics.manage');

        $song->delete();
        $this->syncCorpus();

        return response()->json(['ok' => true]);
    }

    /**
     * Validate + normalise the payload. On update every field is optional
     * (PATCH-style); has_chords is auto-derived from inline [Chord] markers
     * unless the client sends it explicitly.
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'language'   => [$required, 'string', 'in:my,td'],
            'title'      => [$required, 'string', 'max:255'],
            'artist'     => ['nullable', 'string', 'max:255'],
            'category'   => ['nullable', 'string', 'max:100'],
            'lyrics'     => [$required, 'string'],
            'has_chords' => ['sometimes', 'boolean'],
            'source'     => ['sometimes', 'string', 'max:50'],
            'url'        => ['nullable', 'string', 'max:2048', 'url'],
        ]);

        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }
        if (array_key_exists('artist', $data)) {
            $data['artist'] = $data['artist'] !== null ? trim($data['artist']) : null;
        }
        if (array_key_exists('category', $data)) {
            $data['category'] = $data['category'] !== null ? trim($data['category']) : null;
        }
        if (isset($data['lyrics']) && ! array_key_exists('has_chords', $data)) {
            // Inline ChordPro chords look like [G], [Am7], [C/E] — distinct from
            // section labels such as [Verse 1] / [Chorus] which contain a space.
            $data['has_chords'] = (bool) preg_match('/\[[A-G][^\s\]]*\]/', $data['lyrics']);
        }

        return $data;
    }

    /**
     * Refresh the worker Myanmar lyrics corpus from the DB after a write. The DB
     * is authoritative; the JSON is a derived export. Best-effort so a filesystem
     * hiccup can never fail an otherwise-successful admin save.
     */
    private function syncCorpus(): void
    {
        try {
            SongCorpusService::export();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
