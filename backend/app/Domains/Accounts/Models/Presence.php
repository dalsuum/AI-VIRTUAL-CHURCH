<?php

namespace App\Domains\Accounts\Models;

use App\Enums\PresenceActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presence extends Model
{
    protected $fillable = ['user_id', 'status', 'current_activity', 'activity_ref', 'last_seen_at'];

    protected $casts = [
        'current_activity' => PresenceActivity::class,
        'last_seen_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}
