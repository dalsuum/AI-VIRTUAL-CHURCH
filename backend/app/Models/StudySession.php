<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Bible Study discussion session. Ownership is user_id XOR guest_session_id,
 * enforced by StudySessionPolicy / validation; all reads are owner-scoped.
 *
 * SECURITY: `stream_token` holds only a SHA-256 HASH of the CSPRNG token handed to
 * the client once. We never store or serialize the plaintext (the column is hidden
 * anyway). `owner_fingerprint` is a soft device/network binding (mismatch → log +
 * step-up, never a hard block). Use issueStreamToken()/verifyStreamToken().
 */
class StudySession extends Model
{
    public const STATES = [
        'created', 'framing', 'discussing', 'awaiting_user', 'ending', 'summarized', 'closed',
    ];

    protected $fillable = [
        'user_id', 'guest_session_id', 'language', 'translation', 'style', 'topic',
        'mood', 'agent_count', 'state', 'stream_token', 'owner_fingerprint',
        'contact_email', 'last_activity_at',
    ];

    protected $hidden = ['stream_token', 'owner_fingerprint', 'guest_session_id'];

    protected $casts = [
        'agent_count'      => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(StudyMessage::class, 'session_id');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(StudySummary::class, 'session_id');
    }

    /**
     * Mint a fresh stream token: returns the plaintext ONCE (to hand to the owning
     * client) and persists only its SHA-256 hash. Rotates any previous token.
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
        return hash_equals((string) $this->stream_token, hash('sha256', $plaintext));
    }

    /** Compute the soft owner fingerprint (device/network bind) for this principal. */
    public static function fingerprint(string $owner, ?string $userAgent, ?string $ip): string
    {
        return hash('sha256', $owner . '|' . ($userAgent ?? '') . '|' . self::ipPrefix($ip));
    }

    /** Truncate an IP to /24 (v4) or /48 (v6) so mobile NAT churn doesn't break binding. */
    private static function ipPrefix(?string $ip): string
    {
        if (! $ip) {
            return '';
        }
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)); // /48-ish
        }
        $parts = explode('.', $ip);

        return implode('.', array_slice($parts, 0, 3)); // /24
    }

    public function isOwnedByUser(?int $userId): bool
    {
        return $userId !== null && (int) $this->user_id === $userId;
    }

    public function isOwnedByGuest(?string $guestId): bool
    {
        return $guestId !== null && $this->guest_session_id !== null
            && hash_equals((string) $this->guest_session_id, $guestId);
    }
}
