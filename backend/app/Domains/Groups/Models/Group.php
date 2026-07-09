<?php

namespace App\Domains\Groups\Models;

use App\Domains\Church\Models\Church;
use App\Enums\GroupType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = ['church_id', 'name', 'type', 'description', 'settings'];

    protected $casts = [
        'type'     => GroupType::class,
        'settings' => 'array',
    ];

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_memberships')
                    ->withPivot(['role', 'status', 'joined_at'])
                    ->withTimestamps();
    }
}
