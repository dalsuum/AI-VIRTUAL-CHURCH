<?php

namespace App\Domains\Church\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Church extends Model
{
    protected $fillable = ['parent_id', 'name', 'slug', 'timezone', 'settings'];

    protected $casts = ['settings' => 'array'];

    /** church → campus → community hierarchy. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ChurchMembership::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(\App\Domains\Groups\Models\Group::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'church_memberships')
                    ->withPivot(['role', 'status', 'joined_at'])
                    ->withTimestamps();
    }
}
