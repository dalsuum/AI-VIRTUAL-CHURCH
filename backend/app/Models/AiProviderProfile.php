<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A configured AI provider connection (OpenRouter / Ollama / RunPod / LM Studio /
 * generic OpenAI-compatible).
 *
 * SECURITY: the credential is NEVER exposed. `key_ciphertext` is encrypted at rest
 * via the `encrypted` cast and is $hidden; `key_ref` (env/secret name) is hidden too.
 * Serialize with the `key_set` accessor so callers learn only whether a key exists.
 */
class AiProviderProfile extends Model
{
    public const TYPES = ['openrouter', 'ollama', 'runpod', 'lmstudio', 'openai_compatible'];

    protected $fillable = [
        'name', 'type', 'base_url', 'model', 'key_ciphertext', 'key_ref', 'params', 'enabled',
    ];

    protected $hidden = ['key_ciphertext', 'key_ref'];

    protected $appends = ['key_set'];

    protected $casts = [
        'key_ciphertext' => 'encrypted',
        'params'         => 'array',
        'enabled'        => 'boolean',
    ];

    /** Public-safe signal: does this profile have a credential, without revealing it. */
    public function getKeySetAttribute(): bool
    {
        return ! empty($this->attributes['key_ciphertext']) || ! empty($this->key_ref);
    }

    /**
     * Resolve the actual secret at dispatch time (server-side only). Prefers the
     * encrypted column; falls back to the named env/secret. Never logged.
     */
    public function resolveKey(): ?string
    {
        if (! empty($this->attributes['key_ciphertext'])) {
            return $this->key_ciphertext; // decrypted by the cast
        }

        return $this->key_ref ? env($this->key_ref) : null;
    }
}
