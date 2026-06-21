<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A registered tool the orchestrator may expose to a plugin. The registry is
 * closed: a plugin can invoke a tool only when joined via ManifestTool. The
 * `handler_ref` maps to a code-side handler — it is never executed dynamically
 * from user input — so it is hidden from serialization.
 */
class AiTool extends Model
{
    protected $fillable = ['name', 'json_schema', 'handler_ref', 'scopes', 'enabled'];

    protected $hidden = ['handler_ref'];

    protected $casts = [
        'json_schema' => 'array',
        'scopes'      => 'array',
        'enabled'     => 'boolean',
    ];
}
