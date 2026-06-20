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
            foreach ($cat['keywords'] as $kw) {
                $words[] = $kw;
            }
        }
        $clean = array_values(array_unique(array_filter(array_map('trim', $words))));
        return $clean ?: self::DEFAULT_FILTER_KEYWORDS;
    }

    /**
     * Keywords that apply to a given search scope ('music' or 'sermon').
     * A category tagged 'both' contributes to either scope.
     */
    public static function filterKeywordsForScope(string $scope): array
    {
        $words = [];
        foreach (static::filterCategories() as $cat) {
            if ($cat['scope'] === 'both' || $cat['scope'] === $scope) {
                foreach ($cat['keywords'] as $kw) {
                    $words[] = $kw;
                }
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $words))));
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
                'keywords'    => $keywords,
            ];
        }

        return $clean;
    }
}
