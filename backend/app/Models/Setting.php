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
    public const NARRATION_MODES = ['off', 'browser', 'openai', 'kokoro'];

    /** Where generated audio is stored (see storage_backend setting). */
    public const STORAGE_BACKENDS = ['local', 's3'];

    /** Every music source the app can offer; admins enable a subset (music_sources). */
    public const MUSIC_SOURCES = ['hymn_sung', 'hymn', 'suno', 'youtube'];

    /** The moods a worshipper can pick from out of the box, before any admin edits. */
    public const DEFAULT_MOODS = ['Grateful', 'Anxious', 'Grieving', 'Joyful', 'Seeking', 'Hopeful'];

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
}
