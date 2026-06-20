<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Online Bible reader — public, read-only proxy to the local FastAPI worker
 * (workers/bible_router.py), which serves the vendored public-domain
 * translations (en BSB, my Judson 1835, td Tedim 1932) it already keeps in
 * memory. Laravel adds a long-lived cache layer so the SPA's book list and
 * chapter fetches are instant and never re-hit the worker for the same page.
 */
class BibleController extends Controller
{
    /** Translations the reader exposes — also the validation allow-list. */
    private const LANGS = ['en', 'my', 'td'];

    private function base(): string
    {
        return rtrim((string) config('services.tedim_llm.url', 'http://127.0.0.1:8001'), '/');
    }

    private function lang(Request $request): string
    {
        $lang = (string) $request->query('lang', 'en');

        return in_array($lang, self::LANGS, true) ? $lang : 'en';
    }

    /** Table of contents (book numbers, native names, chapter counts) for a translation. */
    public function books(Request $request)
    {
        $lang = $this->lang($request);

        return Cache::remember("bible:books:{$lang}", now()->addDay(), function () use ($lang) {
            $resp = Http::timeout(10)->get("{$this->base()}/bible/books", ['lang' => $lang]);
            abort_unless($resp->successful(), 502, 'Bible service unavailable');

            return $resp->json();
        });
    }

    /** One chapter's verses. book is 1-66, chapter is 1-based. */
    public function chapter(Request $request)
    {
        $data = $request->validate([
            'lang'    => ['nullable', 'in:' . implode(',', self::LANGS)],
            'book'    => ['required', 'integer', 'between:1,66'],
            'chapter' => ['required', 'integer', 'min:1'],
        ]);

        $lang    = $data['lang'] ?? 'en';
        $book    = (int) $data['book'];
        $chapter = (int) $data['chapter'];

        return Cache::remember("bible:ch:{$lang}:{$book}:{$chapter}", now()->addDay(), function () use ($lang, $book, $chapter) {
            $resp = Http::timeout(10)->get("{$this->base()}/bible/chapter", [
                'lang'    => $lang,
                'book'    => $book,
                'chapter' => $chapter,
            ]);
            if ($resp->status() === 404) {
                abort(404, 'Chapter not found');
            }
            abort_unless($resp->successful(), 502, 'Bible service unavailable');

            return $resp->json();
        });
    }

    /**
     * Narrate a chapter aloud. Resolves the per-language voice provider from
     * admin Settings (same as the service pipeline) and proxies to the worker,
     * which synthesizes once and caches. Returns a playable audio URL.
     */
    public function audio(Request $request)
    {
        $data = $request->validate([
            'lang'    => ['nullable', 'in:' . implode(',', self::LANGS)],
            'book'    => ['required', 'integer', 'between:1,66'],
            'chapter' => ['required', 'integer', 'min:1'],
            'gender'  => ['nullable', 'in:male,female'],
        ]);

        $lang    = $data['lang'] ?? 'en';
        $book    = (int) $data['book'];
        $chapter = (int) $data['chapter'];
        $gender  = $data['gender'] ?? 'female';

        // Reuse the same provider the service narration uses for this language.
        $mode = Setting::narrationMode($lang);
        if (! in_array($mode, Setting::SERVER_NARRATION_MODES, true)) {
            abort(409, 'Voice narration is not available for this translation.');
        }

        // The worker's `voice` field is the Edge voice name for edge_tts, or the
        // Voicebox engine for voicebox; other providers ignore it.
        $voice = match ($mode) {
            'edge_tts' => Setting::get('edge_tts_voice', 'en-US-AriaNeural'),
            'voicebox' => Setting::get('voicebox_engine', 'qwen'),
            default    => '',
        };

        $resp = Http::timeout((int) config('services.tedim_llm.bible_audio_timeout', 600))
            ->post("{$this->base()}/bible/narrate", [
                'lang'            => $lang,
                'book'            => $book,
                'chapter'         => $chapter,
                'mode'            => $mode,
                'gender'          => $gender,
                'voice'           => $voice,
                'storage_backend' => (string) Setting::get('storage_backend', 'local'),
            ]);

        if ($resp->status() === 404) {
            abort(404, 'Chapter not found');
        }
        abort_unless($resp->successful(), 502, 'Narration service unavailable');

        return $resp->json();
    }
}
