<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fictional pastor or moderator. `display_name`/`avatar_ref` are PUBLIC.
 *
 * SECURITY: `system_prompt` and `tradition_tag` (the real-world inspiration used
 * ONLY to steer tone) are server-only and $hidden — they must never reach a public
 * API or SSE event. The persona never reveals which historical figure inspired it.
 *
 * `weight` (0–100) drives weighted participation: probability of inclusion in a
 * session and per-turn token budget. `module = '*'` = reusable across modules.
 */
class AiPersona extends Model
{
    protected $fillable = [
        'module', 'language', 'display_name', 'avatar_ref', 'tradition_tag',
        'system_prompt', 'weight', 'is_moderator', 'provider_profile_id', 'enabled',
    ];

    protected $hidden = ['system_prompt', 'tradition_tag', 'provider_profile_id'];

    protected $casts = [
        'weight'       => 'integer',
        'is_moderator' => 'boolean',
        'enabled'      => 'boolean',
    ];

    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(AiProviderProfile::class, 'provider_profile_id');
    }

    /** Enabled personas for a module+language, including '*' module-shared ones. */
    public function scopeForModuleLanguage($query, string $module, string $language)
    {
        return $query->where('language', $language)
            ->where('enabled', true)
            ->where(fn ($q) => $q->where('module', $module)->orWhere('module', '*'));
    }
}
