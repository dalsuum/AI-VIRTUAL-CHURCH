<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * Read-only public share link for a session. SECURITY: `token` is stored as a
 * SHA-256 HASH only (plaintext returned to the owner once); `password` is bcrypt.
 */
class ChatSessionShare extends Model
{
    protected $fillable = [
        'chat_session_id', 'token', 'password', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['token', 'password'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    /** Mint a share token: persist the SHA-256 hash, return the plaintext once. */
    public function issueToken(): string
    {
        $plaintext = bin2hex(random_bytes(24));
        $this->token = hash('sha256', $plaintext);

        return $plaintext;
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function setPassword(?string $plain): void
    {
        $this->password = $plain ? Hash::make($plain) : null;
    }

    public function checkPassword(?string $plain): bool
    {
        if ($this->password === null) {
            return true;                       // no password set
        }

        return $plain !== null && Hash::check($plain, $this->password);
    }
}
