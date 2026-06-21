<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A role prompt template per module+language. The `body` is admin-editable but
 * server-only ($hidden): the prompt engine always prepends immutable core
 * invariants and wraps untrusted context regardless of body contents, so an edit
 * can never remove the trust fences.
 */
class AiPromptTemplate extends Model
{
    public const ROLES = ['frame', 'pastor', 'synthesis', 'summary', 'system'];

    protected $fillable = [
        'module', 'language', 'role', 'body', 'temperature', 'max_tokens', 'enabled',
    ];

    protected $hidden = ['body'];

    protected $casts = [
        'temperature' => 'float',
        'max_tokens'  => 'integer',
        'enabled'     => 'boolean',
    ];

    public function scopeFor($query, string $module, string $language, string $role)
    {
        return $query->where(compact('module', 'language', 'role'))->where('enabled', true);
    }
}
