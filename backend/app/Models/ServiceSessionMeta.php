<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceSessionMeta extends Model
{
    protected $table = 'service_sessions_meta';

    protected $fillable = [
        'chat_session_id', 'service_session_id', 'church_id', 'service_name',
        'speaker', 'notes',
    ];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function serviceSession(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'service_session_id');
    }
}
