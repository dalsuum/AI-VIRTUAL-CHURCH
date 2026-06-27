<?php

namespace App\Domains\Church\Models;

use App\Enums\ChurchRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchMembership extends Model
{
    protected $fillable = ['church_id', 'user_id', 'role', 'status', 'joined_at'];

    protected $casts = [
        'role'      => ChurchRole::class,
        'joined_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
