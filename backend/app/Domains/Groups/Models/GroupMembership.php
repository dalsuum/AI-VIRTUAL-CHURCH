<?php

namespace App\Domains\Groups\Models;

use App\Enums\GroupRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMembership extends Model
{
    protected $fillable = ['group_id', 'user_id', 'role', 'status', 'joined_at'];

    protected $casts = [
        'role'      => GroupRole::class,
        'joined_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
