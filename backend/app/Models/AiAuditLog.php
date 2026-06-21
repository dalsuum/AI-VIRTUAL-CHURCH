<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail for AI Core config changes (persona/prompt/provider/tool/
 * manifest). Use the static record() helper, which redacts secrets before writing —
 * raw API keys and full server-only prompt bodies must never land here.
 */
class AiAuditLog extends Model
{
    public $timestamps = false; // created_at only

    /** Keys whose values are stripped from any before/after diff. */
    public const REDACT_KEYS = ['key_ciphertext', 'key_ref', 'system_prompt', 'body', 'stream_token'];

    protected $fillable = [
        'actor_user_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'ip',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Replace sensitive values with a marker so they never persist in the log. */
    public static function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        foreach (self::REDACT_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    /** Append one audit entry with secrets redacted. */
    public static function record(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
    ): self {
        return static::create([
            'actor_user_id' => $actorId,
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'before'        => self::redact($before),
            'after'         => self::redact($after),
            'ip'            => $ip,
        ]);
    }
}
