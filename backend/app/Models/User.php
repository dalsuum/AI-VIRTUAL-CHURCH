<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    // Role hierarchy (lowest → highest privilege).
    public const ROLE_GUEST     = 'guest';
    public const ROLE_MEMBER    = 'member';
    public const ROLE_PRESENTER = 'presenter';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_ADMIN     = 'admin';

    public const ROLES = [
        self::ROLE_GUEST,
        self::ROLE_MEMBER,
        self::ROLE_PRESENTER,
        self::ROLE_MODERATOR,
        self::ROLE_ADMIN,
    ];

    // Roles an admin may assign to other users (not guest — that's auto).
    public const ASSIGNABLE_ROLES = [
        self::ROLE_MEMBER,
        self::ROLE_PRESENTER,
        self::ROLE_MODERATOR,
        self::ROLE_ADMIN,
    ];

    protected $fillable = [
        'name', 'name_provided', 'email', 'password', 'timezone',
        'music_source', 'presenter_gender', 'is_admin', 'is_blocked',
        'role', 'password_reset_token', 'password_reset_expires_at',
    ];
    protected $hidden = ['password', 'remember_token', 'password_reset_token'];
    protected $casts = [
        'email_verified_at'        => 'datetime',
        'password_reset_expires_at'=> 'datetime',
        'password'                 => 'hashed',
        'is_admin'                 => 'boolean',
        'name_provided'            => 'boolean',
        'is_blocked'               => 'boolean',
    ];

    public function role(): string
    {
        return $this->attributes['role'] ?? (
            $this->is_admin ? self::ROLE_ADMIN : self::ROLE_MEMBER
        );
    }

    public function isAdmin(): bool     { return $this->role() === self::ROLE_ADMIN; }
    public function isModerator(): bool { return in_array($this->role(), [self::ROLE_MODERATOR, self::ROLE_ADMIN]); }
    public function isPresenter(): bool { return in_array($this->role(), [self::ROLE_PRESENTER, self::ROLE_MODERATOR, self::ROLE_ADMIN]); }
    public function isMember(): bool    { return $this->role() !== self::ROLE_GUEST; }

    public function sessions(): HasMany
    {
        return $this->hasMany(ServiceSession::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedger::class);
    }
}
