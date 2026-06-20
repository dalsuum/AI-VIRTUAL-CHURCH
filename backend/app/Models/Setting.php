<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global, admin-editable key/value settings. Use the static get()/set() helpers
 * rather than touching rows directly so reads carry a sane default and writes
 * upsert. Values are stored as strings; callers cast as needed.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** Allowed narration voice modes (see narration_mode setting). */
    public const NARRATION_MODES = ['off', 'browser', 'openai', 'kokoro', 'edge_tts', 'mms_tts', 'voicebox'];

    /** Modes that produce a stored audio file the player can render. */
    public const SERVER_NARRATION_MODES = ['openai', 'kokoro', 'edge_tts', 'mms_tts', 'voicebox'];

    /** Server-side defaults so every language gets an audio player unless disabled. */
    public const DEFAULT_NARRATION_MODES = [
        'en' => 'edge_tts',
        'my' => 'mms_tts',
        'td' => 'mms_tts',
        // Hebrew Bible reader: Edge TTS has native he-IL neural voices.
        'he' => 'edge_tts',
    ];

    /** Edge TTS voice names the admin may pick from. */
    public const EDGE_TTS_VOICES = [
        'en-US-AriaNeural',
        'en-US-JennyNeural',
        'en-GB-SoniaNeural',
        'en-AU-NatashaNeural',
        'en-US-GuyNeural',
        'en-US-ChristopherNeural',
        'en-GB-RyanNeural',
        'en-AU-WilliamNeural',
    ];

    /** Where generated audio is stored (see storage_backend setting). */
    public const STORAGE_BACKENDS = ['local', 's3'];

    /** Every music source the app can offer; admins enable a subset (music_sources). */
    public const MUSIC_SOURCES = ['hymn_sung', 'hymn', 'hymn_youtube', 'suno', 'youtube', 'musicgen', 'local_ai'];

    /** The moods a worshipper can pick from out of the box, before any admin edits. */
    public const DEFAULT_MOODS = ['Grateful', 'Anxious', 'Grieving', 'Joyful', 'Seeking', 'Hopeful'];

    /**
     * Default non-Christian religious keywords rejected from YouTube search results.
     * Admin can extend or trim this list via the Content Filter settings panel.
     */
    public const DEFAULT_FILTER_KEYWORDS = [
        'buddhism', 'buddhist', 'buddha', 'dharma', 'sangha',
        'monk', 'monks', 'monastery', 'zen',
        'hindu', 'hinduism', 'vedic',
        'islam', 'islamic', 'muslim', 'quran', 'quranic', 'allah', 'mosque',
        'rabbi', 'synagogue', 'jewish', 'judaism', 'torah',
        'new age', 'wicca', 'pagan', 'occult', 'astrology',
        'mindfulness', 'chakra', 'reincarnation',
    ];

    /** Where a filter category applies when blocking YouTube results. */
    public const FILTER_SCOPES = ['both', 'music', 'sermon'];

    /**
     * Firewall-style category type:
     *   'block' — keywords reject a matching YouTube result (default policy).
     *   'allow' — keywords force-keep a matching result even if a block keyword
     *             also matches. Allow takes priority over block (an explicit
     *             exception to the blocklist).
     */
    public const FILTER_TYPES = ['block', 'allow'];

    /**
     * Church-tailored content-filter taxonomy. Each category groups keywords
     * and declares WHERE it blocks: worship/music search, sermon search, or both.
     * Admin-editable via the Content Filter tab; falls back to these defaults.
     * Keywords are matched (case-insensitive) against YouTube result titles and
     * channel names — any hit skips the result.
     */
    public const DEFAULT_FILTER_CATEGORIES = [
        [
            'id' => 'other_religions', 'label' => 'Other Religions', 'scope' => 'both',
            'description' => 'Non-Christian faith traditions (Baptist / Assemblies of God context).',
            'keywords' => [
                'buddhism', 'buddhist', 'buddha', 'dharma', 'sangha',
                'monk', 'monks', 'monastery', 'zen',
                'hindu', 'hinduism', 'vedic',
                'islam', 'islamic', 'muslim', 'quran', 'quranic', 'allah', 'mosque',
                'rabbi', 'synagogue', 'jewish', 'judaism', 'torah',
            ],
        ],
        [
            'id' => 'occult_newage', 'label' => 'Occult / New Age', 'scope' => 'both',
            'description' => 'Esoteric, occult, and new-age spirituality.',
            'keywords' => ['new age', 'wicca', 'pagan', 'occult', 'astrology', 'mindfulness', 'chakra', 'reincarnation'],
        ],
        [
            'id' => 'profanity', 'label' => 'Profanity / Explicit', 'scope' => 'both',
            'description' => 'Explicit or profane terms unsuitable for worship.',
            'keywords' => [],
        ],
        [
            'id' => 'politics', 'label' => 'Politics', 'scope' => 'both',
            'description' => 'Partisan or political content.',
            'keywords' => [],
        ],
        [
            'id' => 'secular_music', 'label' => 'Secular Music', 'scope' => 'music',
            'description' => 'Non-worship music formats (only filters the worship/music search).',
            'keywords' => ['remix', 'reaction', 'karaoke', 'mashup', 'nightcore', 'lyric video reaction'],
        ],
        [
            'id' => 'off_topic_channels', 'label' => 'Off-topic Channels', 'scope' => 'both',
            'description' => 'Channels/titles that signal non-worship content.',
            'keywords' => ['movie clips', 'gaming', 'trailer', 'news'],
        ],
        [
            'id' => 'sermon_exclude', 'label' => 'Sermon-exclude', 'scope' => 'sermon',
            'description' => 'Music/event terms that should never appear in a sermon result.',
            'keywords' => ['concert', 'choir', 'music video', 'instrumental'],
        ],
        [
            'id' => 'custom', 'label' => 'Custom', 'scope' => 'both',
            'description' => 'Your own terms. Added keywords land here unless you pick another category.',
            'keywords' => [],
        ],
        [
            'id' => 'allowlist', 'label' => 'Allowlist', 'scope' => 'both', 'type' => 'allow',
            'description' => 'Trusted terms (channels, artists, ministries). A match here keeps a video even if a block keyword also matches — allow wins over block.',
            'keywords' => [],
        ],
    ];

    /** Admin-curated cards shown while the service is preparing. */
    public const DEFAULT_COUNTDOWN_BANNERS = [
        ['text' => 'Take a quiet breath. God meets us with mercy before we have the right words.', 'source' => 'Pastoral encouragement'],
        ['text' => 'Be still and remember that the Lord is near.', 'source' => 'Psalm 46:10'],
        ['text' => 'May the God of hope fill you with peace as you wait.', 'source' => 'Romans 15:13'],
    ];

    /** All supported service languages. */
    public const LANGUAGES = ['en', 'my', 'td'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->whereKey($key)->value('value') ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function defaultNarrationMode(string $language): string
    {
        return self::DEFAULT_NARRATION_MODES[$language] ?? self::DEFAULT_NARRATION_MODES['en'];
    }

    public static function narrationMode(string $language): string
    {
        $mode = static::get('narration_mode_' . $language, self::defaultNarrationMode($language));
        return in_array($mode, self::NARRATION_MODES, true) ? $mode : self::defaultNarrationMode($language);
    }

    public static function narrationEnabled(string $language): bool
    {
        return static::get('narration_' . $language, '1') === '1';
    }

    /**
     * Voice provider for the online Bible reader's "Listen" button. Admins can
     * pick a Bible-specific voice; when unset it inherits the live-service
     * narration voice for that language so existing behavior is preserved.
     */
    public static function bibleNarrationMode(string $language): string
    {
        $mode = static::get('bible_narration_mode_' . $language);
        if ($mode !== null && in_array($mode, self::NARRATION_MODES, true)) {
            return $mode;
        }
        return static::narrationMode($language);
    }

    /** Whether the Bible reader highlights verses as narration plays. */
    public static function bibleTextHighlightEnabled(): bool
    {
        return static::get('bible_text_highlight_enabled', '1') === '1';
    }

    /** Background-music mode for the Bible reader: 'off' | 'static' | 'ai'. */
    public const BIBLE_BG_MUSIC_MODES = ['off', 'static', 'ai'];

    /** Engines that can generate the AI background loop (matches workers/bible_bg.py). */
    public const BIBLE_BG_MUSIC_ENGINES = ['musicgen', 'local_ai'];

    public static function bibleBgMusicMode(): string
    {
        $mode = (string) static::get('bible_bg_music_mode', 'off');
        return in_array($mode, self::BIBLE_BG_MUSIC_MODES, true) ? $mode : 'off';
    }

    public static function bibleBgMusicEngine(): string
    {
        $engine = (string) static::get('bible_bg_music_engine', 'musicgen');
        return in_array($engine, self::BIBLE_BG_MUSIC_ENGINES, true) ? $engine : 'musicgen';
    }

    /**
     * Optional looping background music played softly behind Bible narration.
     * Empty string disables the static track (the default).
     */
    public static function bibleBgMusicUrl(): string
    {
        $url = trim((string) static::get('bible_bg_music_url', ''));
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /** Background-music volume (0.0–1.0) for Bible narration; defaults to a gentle 0.15. */
    public static function bibleBgMusicVolume(): float
    {
        $vol = (float) static::get('bible_bg_music_volume', '0.15');
        return max(0.0, min(1.0, $vol));
    }

    /**
     * Online Bible reader translations the admin can show/hide and tune, in tab
     * order. Kept in sync with BibleController::LANGS (the API allow-list) and the
     * LANGS array in frontend/src/components/BibleReader.vue.
     */
    public const BIBLE_VERSIONS = [
        'kjv' => 'KJV',
        'en'  => 'English (BSB)',
        'he'  => 'Hebrew (עברית)',
        'my'  => 'Burmese (ဗမာ)',
        'cfm' => 'Falam',
        'cnh' => 'Hakha',
        'mrh' => 'Mara',
        'hlt' => 'Matu',
        'lus' => 'Mizo',
        'pck' => 'Paite',
        'csy' => 'Sizang',
        'td'  => 'Tedim',
    ];

    /**
     * Per-version reader feature buttons the admin can enable/disable. These
     * mirror the controls in BibleReader.vue; 'enabled' (handled separately)
     * shows/hides the whole translation tab.
     */
    public const BIBLE_FEATURES = ['listen', 'highlight', 'continuous', 'music', 'select', 'speed', 'textsize', 'color'];

    /**
     * The full per-version feature matrix for the Bible reader. Every version
     * gets an 'enabled' flag (show its tab) plus a boolean per BIBLE_FEATURES.
     * Defaults everything to true so a fresh install behaves exactly as before;
     * stored admin overrides are merged over that default and validated here.
     */
    public static function bibleFeatureMatrix(): array
    {
        $stored = [];
        $raw = static::get('bible_features');
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        $matrix = [];
        foreach (array_keys(self::BIBLE_VERSIONS) as $code) {
            $row = is_array($stored[$code] ?? null) ? $stored[$code] : [];
            $clean = ['enabled' => ($row['enabled'] ?? true) !== false];
            foreach (self::BIBLE_FEATURES as $feature) {
                $clean[$feature] = ($row[$feature] ?? true) !== false;
            }
            $matrix[$code] = $clean;
        }

        return $matrix;
    }

    /** Whether a translation's tab is shown in the reader. Unknown codes are off. */
    public static function bibleVersionEnabled(string $code): bool
    {
        return static::bibleFeatureMatrix()[$code]['enabled'] ?? false;
    }

    /** Whether a given reader feature button is enabled for a translation. */
    public static function bibleFeatureEnabled(string $code, string $feature): bool
    {
        return static::bibleFeatureMatrix()[$code][$feature] ?? false;
    }

    /** Codes of the translations whose tab is currently shown, in canonical order. */
    public static function enabledBibleVersions(): array
    {
        return array_values(array_filter(
            array_keys(self::BIBLE_VERSIONS),
            fn ($code) => static::bibleVersionEnabled($code),
        ));
    }

    /** Validate, clean, and persist the per-version Bible feature matrix. */
    public static function setBibleFeatures(array $matrix): array
    {
        $clean = [];
        foreach (array_keys(self::BIBLE_VERSIONS) as $code) {
            $row = is_array($matrix[$code] ?? null) ? $matrix[$code] : [];
            $entry = ['enabled' => ($row['enabled'] ?? true) !== false];
            foreach (self::BIBLE_FEATURES as $feature) {
                $entry[$feature] = ($row[$feature] ?? true) !== false;
            }
            $clean[$code] = $entry;
        }
        static::set('bible_features', json_encode($clean));

        return $clean;
    }

    /** Read a JSON-encoded list setting, falling back to $default when unset/garbled. */
    public static function getList(string $key, array $default = []): array
    {
        $raw = static::get($key);
        if ($raw === null) {
            return $default;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : $default;
    }

    /** Persist a list setting as JSON. */
    public static function setList(string $key, array $value): void
    {
        static::set($key, json_encode(array_values($value)));
    }

    /** The worshipper moods offered in the intake form (admin-editable). */
    public static function moods(): array
    {
        return static::getList('moods', self::DEFAULT_MOODS);
    }

    /** Countdown banner cards shown while a service prepares. */
    public static function countdownBanners(): array
    {
        $items = static::getList('countdown_banners', self::DEFAULT_COUNTDOWN_BANNERS);
        $clean = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $text = trim($item);
                $source = '';
            } elseif (is_array($item)) {
                $text = trim((string) ($item['text'] ?? ''));
                $source = trim((string) ($item['source'] ?? ''));
            } else {
                continue;
            }

            if ($text === '') {
                continue;
            }

            $clean[] = [
                'text' => mb_substr($text, 0, 300),
                'source' => mb_substr($source, 0, 80),
            ];
        }

        return $clean ?: self::DEFAULT_COUNTDOWN_BANNERS;
    }

    /**
     * The music sources currently offered in the intake form, in canonical order.
     * Always a non-empty subset of MUSIC_SOURCES — falls back to all sources when
     * unset so a fresh install behaves exactly as before.
     */
    public static function enabledMusicSources(): array
    {
        $enabled = static::getList('music_sources', self::MUSIC_SOURCES);
        $valid = array_values(array_intersect(self::MUSIC_SOURCES, $enabled));

        return $valid ?: self::MUSIC_SOURCES;
    }

    /** Whether worshippers may schedule a service for a future time. */
    public static function schedulingEnabled(): bool
    {
        return static::get('scheduling_enabled', '1') === '1';
    }

    /**
     * The service languages currently offered in the intake form.
     * English defaults on; Myanmar and Tedim default off until the admin enables them.
     */
    public static function enabledLanguages(): array
    {
        $defaults = ['en' => '1', 'my' => '0', 'td' => '0'];
        return array_values(array_filter(
            self::LANGUAGES,
            fn($l) => static::get('lang_' . $l, $defaults[$l]) === '1'
        ));
    }

    /** The music source pre-selected in the intake form. Must be in MUSIC_SOURCES. */
    public static function defaultMusicSource(): string
    {
        $v = static::get('default_music_source', 'youtube');
        return in_array($v, self::MUSIC_SOURCES, true) ? $v : 'youtube';
    }

    /**
     * Keywords rejected from YouTube search results to keep non-Christian religious
     * content out of the service. Admin-editable; falls back to the built-in defaults.
     */
    public static function filterKeywords(): array
    {
        $words = [];
        foreach (static::filterCategories() as $cat) {
            if (($cat['type'] ?? 'block') !== 'block') {
                continue;
            }
            foreach ($cat['keywords'] as $kw) {
                $words[] = $kw;
            }
        }
        $clean = array_values(array_unique(array_filter(array_map('trim', $words))));
        return $clean ?: self::DEFAULT_FILTER_KEYWORDS;
    }

    /**
     * Keywords of a given type ('block'|'allow') that apply to a search scope
     * ('music' or 'sermon'). A category tagged 'both' contributes to either scope.
     */
    public static function filterKeywordsForScope(string $scope, string $type = 'block'): array
    {
        $words = [];
        foreach (static::filterCategories() as $cat) {
            if (($cat['type'] ?? 'block') !== $type) {
                continue;
            }
            if ($cat['scope'] === 'both' || $cat['scope'] === $scope) {
                foreach ($cat['keywords'] as $kw) {
                    $words[] = $kw;
                }
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $words))));
    }

    /**
     * Allowlist keywords for a scope — trusted terms that override the blocklist.
     */
    public static function allowKeywordsForScope(string $scope): array
    {
        return static::filterKeywordsForScope($scope, 'allow');
    }

    /**
     * The full categorized content filter. Falls back to the built-in taxonomy,
     * and one-time migrates any legacy flat `content_filter_keywords` the admin
     * previously added (words not in a default category) into the Custom group.
     */
    public static function filterCategories(): array
    {
        $raw = static::get('content_filter_categories');
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return static::normalizeCategories($decoded);
            }
        }

        // No categorized data yet — seed from defaults and fold in legacy keywords.
        $cats = static::normalizeCategories(self::DEFAULT_FILTER_CATEGORIES);

        $legacy = static::getList('content_filter_keywords', []);
        if ($legacy) {
            $known = [];
            foreach ($cats as $cat) {
                foreach ($cat['keywords'] as $kw) {
                    $known[$kw] = true;
                }
            }
            $extra = [];
            foreach ($legacy as $kw) {
                $kw = mb_strtolower(trim((string) $kw));
                if ($kw !== '' && ! isset($known[$kw]) && ! in_array($kw, $extra, true)) {
                    $extra[] = $kw;
                }
            }
            if ($extra) {
                foreach ($cats as &$cat) {
                    if ($cat['id'] === 'custom') {
                        $cat['keywords'] = array_values(array_unique([...$cat['keywords'], ...$extra]));
                    }
                }
                unset($cat);
            }
        }

        return $cats;
    }

    /** Validate, clean, and persist the categorized content filter. */
    public static function setCategories(array $categories): array
    {
        $clean = static::normalizeCategories($categories);
        static::set('content_filter_categories', json_encode($clean));
        // Keep the flat key in sync so older readers (e.g. /config consumers) work.
        static::setList('content_filter_keywords', static::filterKeywords());
        return $clean;
    }

    /** Coerce arbitrary input into the canonical category shape. */
    public static function normalizeCategories(array $categories): array
    {
        $clean = [];
        $seenIds = [];

        foreach ($categories as $cat) {
            if (! is_array($cat)) {
                continue;
            }
            $label = trim((string) ($cat['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = trim((string) ($cat['id'] ?? ''));
            if ($id === '') {
                $id = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label));
                $id = trim($id, '_');
            }
            if ($id === '' || isset($seenIds[$id])) {
                $id = $id . '_' . substr(md5($label . count($clean)), 0, 6);
            }
            $seenIds[$id] = true;

            $scope = (string) ($cat['scope'] ?? 'both');
            if (! in_array($scope, self::FILTER_SCOPES, true)) {
                $scope = 'both';
            }

            $type = (string) ($cat['type'] ?? 'block');
            if (! in_array($type, self::FILTER_TYPES, true)) {
                $type = 'block';
            }

            $keywords = [];
            foreach ((array) ($cat['keywords'] ?? []) as $kw) {
                $kw = mb_strtolower(trim((string) $kw));
                if ($kw !== '' && mb_strlen($kw) <= 100 && ! in_array($kw, $keywords, true)) {
                    $keywords[] = $kw;
                }
            }

            $clean[] = [
                'id'          => mb_substr($id, 0, 60),
                'label'       => mb_substr($label, 0, 80),
                'description' => mb_substr(trim((string) ($cat['description'] ?? '')), 0, 200),
                'scope'       => $scope,
                'type'        => $type,
                'keywords'    => $keywords,
            ];
        }

        return $clean;
    }
}
