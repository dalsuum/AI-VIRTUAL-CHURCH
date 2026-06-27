<?php

namespace App\Domains\Accounts\Models;

use App\Enums\Visibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacySetting extends Model
{
    protected $fillable = [
        'user_id', 'profile_visibility', 'activity_visibility', 'presence_visibility',
        'friend_only_mode', 'incognito',
    ];

    protected $casts = [
        'profile_visibility'  => Visibility::class,
        'activity_visibility' => Visibility::class,
        'presence_visibility' => Visibility::class,
        'friend_only_mode'    => 'boolean',
        'incognito'           => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
