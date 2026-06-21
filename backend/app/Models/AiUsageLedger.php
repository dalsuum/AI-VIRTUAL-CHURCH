<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-turn token + cost telemetry, written from the /internal/study-turn webhook.
 * Cost is stored as integer micros (millionths of a currency unit) to avoid float
 * drift; admin analytics aggregate the scalar columns cheaply.
 */
class AiUsageLedger extends Model
{
    protected $table = 'ai_usage_ledger';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'module', 'session_id', 'provider', 'model',
        'prompt_tokens', 'completion_tokens', 'cost_micros',
    ];

    protected $casts = [
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'cost_micros'       => 'integer',
        'created_at'        => 'datetime',
    ];
}
