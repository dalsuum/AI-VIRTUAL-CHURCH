<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceIntake extends Model
{
    protected $fillable = [
        'session_id', 'mood', 'custom_mood', 'prayer_text', 'scripture_ref', 'music_prompt', 'music_query',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'session_id');
    }
}
