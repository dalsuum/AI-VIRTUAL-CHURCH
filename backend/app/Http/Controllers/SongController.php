<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Services\PermissionService;
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

        return response()->json(['ok' => true, 'song' => $song], 201);
    }

    public function update(Request $request, Song $song): JsonResponse
    {
        PermissionService::require($request->user(), 'lyrics.manage');

        $song->fill($this->validated($request, true));
        $song->save();

        return response()->json(['ok' => true, 'song' => $song->fresh()]);
    }

    public function destroy(Request $request, Song $song): JsonResponse
    {
        PermissionService::require($request->user(), 'lyrics.manage');

        $song->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Bulk import from an uploaded CSV or JSON file. Existing songs (same
     * title + language) are skipped, so re-importing the same file is a no-op.
     * Returns a per-file summary: how many were added, skipped, and any rows
     * that could not be parsed.
     *
     * CSV columns (header row, case-insensitive): language, title, artist,
     * category, lyrics, url. JSON: an array of objects with the same keys.
     */
    public function import(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'lyrics.manage');

        $request->validate([
            // 5 MB cap; CSV is often sniffed as text/plain, so allow txt too.
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,json'],
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());

        try {
            $rows = $ext === 'json'
                ? $this->parseJsonImport($file->getRealPath())
                : $this->parseCsvImport($file->getRealPath());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Could not parse file: ' . $e->getMessage()], 422);
        }

        // Existing title|language keys, lower-cased, so the dedupe is case-insensitive.
        $existing = Song::query()
            ->get(['title', 'language'])
            ->map(fn ($s) => $this->dedupeKey($s->title, $s->language))
            ->flip();

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $i => $raw) {
            $song = $this->normaliseImportRow($raw);
            if ($song === null) {
                $errors[] = 'Row ' . ($i + 1) . ': missing title or lyrics.';
                continue;
            }

            $key = $this->dedupeKey($song['title'], $song['language']);
            if ($existing->has($key)) {
                $skipped++;
                continue;
            }

            Song::create($song);
            $existing->put($key, true);   // guard against duplicates within the file
            $imported++;
        }

        return response()->json([
            'ok'       => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => array_slice($errors, 0, 20),   // cap the payload
        ]);
    }

    /** Lower-cased "title|language" key for case-insensitive dedupe. */
    private function dedupeKey(string $title, string $language): string
    {
        return mb_strtolower(trim($title)) . '|' . $language;
    }

    /** Parse a JSON import file into an array of raw row arrays. */
    private function parseJsonImport(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? array_values($data) : [];
    }

    /** Parse a CSV import file (quoted, multi-line lyrics supported) into rows. */
    private function parseCsvImport(string $path): array
    {
        $rows   = [];
        $header = null;
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        try {
            while (($cols = fgetcsv($fh)) !== false) {
                if ($header === null) {
                    $header = array_map(fn ($h) => strtolower(trim((string) $h)), $cols);
                    continue;
                }
                // Pad/truncate so array_combine never fails on ragged rows.
                $cols = array_pad(array_slice($cols, 0, count($header)), count($header), null);
                $rows[] = array_combine($header, $cols);
            }
        } finally {
            fclose($fh);
        }

        return $rows;
    }

    /**
     * Turn a raw import row (from CSV or JSON) into a validated songs payload,
     * or null when it lacks the required title/lyrics. Unknown languages fall
     * back to 'my'; has_chords is derived from inline chord markers.
     */
    private function normaliseImportRow(array $raw): ?array
    {
        $get = fn (string $k) => isset($raw[$k]) ? trim((string) $raw[$k]) : '';

        $title  = $get('title');
        $lyrics = isset($raw['lyrics']) ? (string) $raw['lyrics'] : '';
        if ($title === '' || trim($lyrics) === '') {
            return null;
        }

        $language = strtolower($get('language'));
        if (! in_array($language, self::LANGUAGES, true)) {
            $language = 'my';
        }

        return [
            'language'   => $language,
            'title'      => $title,
            'artist'     => $get('artist') ?: null,
            'category'   => $get('category') ?: null,
            'lyrics'     => $lyrics,
            'has_chords' => (bool) preg_match('/\[[A-G][^\s\]]*\]/', $lyrics),
            'source'     => $get('source') ?: 'import',
            'url'        => $get('url') ?: null,
        ];
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
}
