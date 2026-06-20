<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Online Bible reader — public, read-only proxy to the local FastAPI worker
 * (workers/bible_router.py), which serves the vendored public-domain
 * translations (en BSB, kjv King James Version, my Judson 1835, td Tedim 1932,
 * he Hebrew WLC — Old Testament only, right-to-left — plus the Chin/Zo language
 * Bibles from the Bible Society of Myanmar: cfm Falam, cnh Hakha, lus Mizo,
 * pck Paite, csy Sizang, mrh Mara, hlt Matu) it already keeps in memory. Laravel adds a long-lived cache layer so the SPA's
 * book list and chapter fetches are instant and never re-hit the worker for the
 * same page.
 */
class BibleController extends Controller
{
    /** Translations the reader exposes — also the validation allow-list. */
    private const LANGS = [
        'en', 'kjv', 'my', 'td', 'he',
        'cfm', 'cnh', 'lus', 'pck', 'csy', 'mrh', 'hlt',
    ];

    private function base(): string
    {
        return rtrim((string) config('services.tedim_llm.url', 'http://127.0.0.1:8001'), '/');
    }

    private function lang(Request $request): string
    {
        $lang = (string) $request->query('lang', 'en');

        return in_array($lang, self::LANGS, true) ? $lang : 'en';
    }

    /**
     * Reject access to a translation the admin has hidden (its tab is off in the
     * Bible feature matrix), so a disabled version can't be reached via the API.
     */
    private function assertVersionEnabled(string $lang): void
    {
        abort_unless(Setting::bibleVersionEnabled($lang), 404, 'Translation not available.');
    }

    /** Public reader config — which languages can be narrated + highlight toggle. */
    public function config()
    {
        $narratable = [];
        foreach (self::LANGS as $l) {
            $narratable[$l] = in_array(Setting::bibleNarrationMode($l), Setting::SERVER_NARRATION_MODES, true);
        }

        return [
            'narratable'      => $narratable,
            'text_highlight'  => Setting::bibleTextHighlightEnabled(),
            'bg_music_mode'   => Setting::bibleBgMusicMode(),
            'bg_music_url'    => Setting::bibleBgMusicUrl(),
            'bg_music_volume' => Setting::bibleBgMusicVolume(),
            // Which translation tabs are shown + the per-version feature button matrix.
            'versions'        => Setting::enabledBibleVersions(),
            'features'        => Setting::bibleFeatureMatrix(),
        ];
    }

    /**
     * Resolve the AI background-music loop for a chapter + the reader's local
     * time of day. Proxies to the worker, which derives a coarse theme from the
     * chapter text, returns a cached track when one exists, or enqueues a one-off
     * MusicGen generation and reports generating=true. Only valid in 'ai' mode.
     */
    public function bgMusic(Request $request)
    {
        $data = $request->validate([
            'lang'    => ['nullable', 'in:' . implode(',', self::LANGS)],
            'book'    => ['required', 'integer', 'between:1,66'],
            'chapter' => ['required', 'integer', 'min:1'],
            'hour'    => ['nullable', 'integer', 'between:0,23'],
        ]);

        $this->assertVersionEnabled($data['lang'] ?? 'en');

        if (Setting::bibleBgMusicMode() !== 'ai') {
            abort(409, 'AI background music is not enabled.');
        }

        $resp = Http::timeout(15)->post("{$this->base()}/bible/bg-music", [
            'lang'            => $data['lang'] ?? 'en',
            'book'            => (int) $data['book'],
            'chapter'         => (int) $data['chapter'],
            'hour'            => (int) ($data['hour'] ?? 12),
            'engine'          => Setting::bibleBgMusicEngine(),
            'storage_backend' => (string) Setting::get('storage_backend', 'local'),
        ]);

        if ($resp->status() === 404) {
            abort(404, 'Chapter not found');
        }
        abort_unless($resp->successful(), 502, 'Background music service unavailable');

        return $resp->json();
    }

    /** Table of contents (book numbers, native names, chapter counts) for a translation. */
    public function books(Request $request)
    {
        $lang = $this->lang($request);
        $this->assertVersionEnabled($lang);

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

        $this->assertVersionEnabled($lang);

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

        $this->assertVersionEnabled($lang);

        // Bible-specific voice (falls back to the service voice when unset).
        $mode = Setting::bibleNarrationMode($lang);
        if (! in_array($mode, Setting::SERVER_NARRATION_MODES, true)) {
            abort(409, 'Voice narration is not available for this translation.');
        }

        // The worker's `voice` field is the Edge voice name for edge_tts, or the
        // Voicebox engine for voicebox; other providers ignore it. Edge voices are
        // language-specific — an English voice cannot synthesize Burmese/Tedim text
        // (the worker returns "No audio was received"), so resolve per-language the
        // same way the service pipeline does in agent_orchestrator._narrate_voice().
        $voice = match ($mode) {
            'edge_tts' => $this->edgeVoice($lang, $gender),
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

    /**
     * Resolve the Microsoft Edge TTS voice for a language/gender. Burmese has
     * native my-MM neural voices; Tedim has no Zolai voice so it uses an English
     * phonetic read; English uses the admin-selected voice. Mirrors the service
     * pipeline (workers/agent_orchestrator.py::_narrate_voice).
     */
    private function edgeVoice(string $lang, string $gender): string
    {
        return match ($lang) {
            'my'    => $gender === 'male' ? 'my-MM-ThihaNeural' : 'my-MM-NilarNeural',
            'td'    => $gender === 'male' ? 'en-US-GuyNeural' : 'en-US-AriaNeural',
            'he'    => $gender === 'male' ? 'he-IL-AvriNeural' : 'he-IL-HilaNeural',
            default => Setting::get('edge_tts_voice', 'en-US-AriaNeural'),
        };
    }
}
