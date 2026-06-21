<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AI Core plugin manifest — one row per ministry module (bible_study, ai_sermon …).
 * Adding a module = inserting a validated manifest + assets; no Core code change.
 *
 * `config` is server-only orchestration data and is hidden from serialization.
 * Activation is fail-closed: only a manifest that passes validation may reach
 * status='active' (enforced in the admin service layer, not here).
 */
class ModuleManifest extends Model
{
    public const STATUSES        = ['draft', 'active', 'invalid'];
    public const MEMORY_STRATEGIES = ['none', 'window', 'summary', 'semantic'];

    /** Hard platform bounds on the discussion agent count (UI/admin may narrow). */
    public const AGENT_COUNT_MIN = 2;
    public const AGENT_COUNT_MAX = 7;

    protected $fillable = [
        'key', 'display_name', 'enabled', 'status', 'languages',
        'default_agent_count', 'min_agent_count', 'max_agent_count',
        'memory_strategy', 'rag_sources', 'config', 'validated_at',
    ];

    protected $hidden = ['config'];

    protected $casts = [
        'enabled'      => 'boolean',
        'languages'    => 'array',
        'rag_sources'  => 'array',
        'config'       => 'array',
        'validated_at' => 'datetime',
    ];

    public function personas(): HasMany
    {
        return $this->hasMany(AiPersona::class, 'module', 'key');
    }

    public function promptTemplates(): HasMany
    {
        return $this->hasMany(AiPromptTemplate::class, 'module', 'key');
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->status === 'active';
    }

    /** Clamp a requested agent count into this manifest's bounds (and platform bounds). */
    public function clampAgentCount(int $requested): int
    {
        $min = max(self::AGENT_COUNT_MIN, (int) $this->min_agent_count);
        $max = min(self::AGENT_COUNT_MAX, (int) $this->max_agent_count);

        return max($min, min($max, $requested));
    }
}
