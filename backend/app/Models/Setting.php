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
    public const MUSIC_SOURCES = ['hymn_sung', 'hymn', 'hymn_youtube', 'suno', 'youtube', 'musicgen'];

    /** The moods a worshipper can pick from out of the box, before any admin edits. */
    public const DEFAULT_MOODS = ['Grateful', 'Anxious', 'Grieving', 'Joyful', 'Seeking', 'Hopeful'];

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
}
