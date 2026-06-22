<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The unified history spine — one row per interaction across every module. All
 * reads are owner-scoped (scopeForUser); never expose another user's sessions.
 *
 * SECURITY: `stream_token` holds only a SHA-256 HASH of the live SSE token handed
 * to the owning client once (mirrors StudySession). Use issue/verifyStreamToken().
 */
class ChatSession extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = [
        'bible_study', 'prayer', 'music', 'service', 'pastor', 'devotion', 'general',
    ];

    protected $fillable = [
        'user_id', 'session_type', 'title', 'language', 'status', 'summary', 'mood',
        'pinned', 'favorite', 'archived', 'rating', 'stream_token',
        'started_at', 'last_activity_at', 'ended_at',
    ];

    protected $hidden = ['stream_token'];

    protected $casts = [
        'pinned'           => 'boolean',
        'favorite'         => 'boolean',
        'archived'         => 'boolean',
        'rating'           => 'integer',
        'started_at'       => 'datetime',
        'last_activity_at' => 'datetime',
        'ended_at'         => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id')->orderBy('created_at');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ChatSessionTag::class, 'chat_session_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ChatSessionShare::class, 'chat_session_id');
    }

    public function bibleMeta(): HasOne
    {
        return $this->hasOne(BibleSessionMeta::class, 'chat_session_id');
    }

    public function musicMeta(): HasOne
    {
        return $this->hasOne(MusicSessionMeta::class, 'chat_session_id');
    }

    public function serviceMeta(): HasOne
    {
        return $this->hasOne(ServiceSessionMeta::class, 'chat_session_id');
    }

    public function prayerMeta(): HasOne
    {
        return $this->hasOne(PrayerSessionMeta::class, 'chat_session_id');
    }

    /** The metadata relation name for a given session type (null if none). */
    public function metaRelation(): ?string
    {
        return match ($this->session_type) {
            'bible_study' => 'bibleMeta',
            'music'       => 'musicMeta',
            'service'     => 'serviceMeta',
            'prayer'      => 'prayerMeta',
            default       => null,
        };
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('archived', false);
    }

    /**
     * Mint a fresh SSE stream token: returns the plaintext ONCE and persists only its
     * SHA-256 hash. Caller is responsible for saving the model.
     */
    public function issueStreamToken(): string
    {
        $plaintext = bin2hex(random_bytes(32)); // CSPRNG
        $this->stream_token = hash('sha256', $plaintext);

        return $plaintext;
    }

    /** Constant-time check that a presented plaintext token matches this session. */
    public function verifyStreamToken(string $plaintext): bool
    {
        return $this->stream_token !== null
            && hash_equals((string) $this->stream_token, hash('sha256', $plaintext));
    }
}
