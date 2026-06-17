<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAsset extends Model
{
    protected $fillable = [
        'session_id', 'segment', 'asset_type', 'storage_key', 'audio_key',
        'provider_ref', 'text_payload', 'tedim_text', 'burmese_text', 'lyrics', 'timings', 'status', 'ready_at',
    ];
    protected $casts = ['ready_at' => 'datetime', 'timings' => 'array'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'session_id');
    }
}
