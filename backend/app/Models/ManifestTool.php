<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Allow-list join: which registered tools a module may invoke. The orchestrator
 * resolves a plugin's tools through enabled rows here and trusts nothing else.
 */
class ManifestTool extends Model
{
    protected $fillable = ['module', 'tool_id', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AiTool::class, 'tool_id');
    }
}
