<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceSession extends Model
{
    protected $fillable = [
        'user_id', 'session_token', 'status', 'music_source', 'language', 'tedim_status', 'burmese_status', 'presenter_gender', 'scheduled_at', 'contact_email', 'started_at', 'ended_at',
    ];
    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function intake(): HasOne
    {
        return $this->hasOne(ServiceIntake::class, 'session_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ServiceAsset::class, 'session_id');
    }
}
