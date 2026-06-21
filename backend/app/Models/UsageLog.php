<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-request AI usage record. See the create_usage_logs_table migration. */
class UsageLog extends Model
{
    protected $table = 'usage_logs';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'user_id', 'guest_ref', 'service', 'model', 'tokens',
        'cost_micros', 'latency_ms', 'status', 'request_id',
    ];

    protected $casts = [
        'tokens'      => 'integer',
        'cost_micros' => 'integer',
        'latency_ms'  => 'integer',
        'created_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
