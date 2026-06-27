<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const AUTH_SESSION_VERSION_KEY = 'auth_session_version';

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
        'name', 'name_provided', 'email', 'password', 'auth_session_version', 'timezone',
        'music_source', 'presenter_gender', 'is_admin', 'is_blocked',
        'status', 'activation_token', 'activation_expires_at',
        'role', 'password_reset_token', 'password_reset_expires_at',
        'subscription_plan', 'subscription_expires_at', 'stripe_customer_id',
        'stripe_subscription_id', 'token_balance', 'monthly_allowance', 'tokens_refilled_at',
        'fav_language', 'fav_bible_version', 'fav_worship_language', 'fav_pastor',
        'fav_worship_style', 'fav_books', 'fav_topics', 'spiritual_goals', 'ai_memory_enabled',
    ];
    protected $hidden = ['password', 'remember_token', 'password_reset_token', 'activation_token', 'stripe_customer_id', 'stripe_subscription_id'];

    // Account lifecycle. PENDING accounts exist but cannot authenticate until the
    // email-activation link is clicked; ACTIVE is the normal, usable state.
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';

    protected $casts = [
        'email_verified_at'        => 'datetime',
        'activation_expires_at'    => 'datetime',
        'password_reset_expires_at'=> 'datetime',
        'subscription_expires_at'  => 'datetime',
        'tokens_refilled_at'       => 'datetime',
        'password'                 => 'hashed',
        'is_admin'                 => 'boolean',
        'name_provided'            => 'boolean',
        'is_blocked'               => 'boolean',
        'auth_session_version'     => 'integer',
        'token_balance'            => 'integer',
        'monthly_allowance'        => 'integer',
        'fav_books'                => 'array',
        'fav_topics'               => 'array',
        'ai_memory_enabled'        => 'boolean',
    ];

    public function role(): string
    {
        return $this->attributes['role'] ?? (
            $this->isGuestAccount() ? self::ROLE_GUEST : (
                $this->is_admin ? self::ROLE_ADMIN : self::ROLE_MEMBER
            )
        );
    }

    public function isAdmin(): bool     { return $this->role() === self::ROLE_ADMIN; }
    public function isModerator(): bool { return in_array($this->role(), [self::ROLE_MODERATOR, self::ROLE_ADMIN]); }
    public function isPresenter(): bool { return in_array($this->role(), [self::ROLE_PRESENTER, self::ROLE_MODERATOR, self::ROLE_ADMIN]); }
    public function isMember(): bool    { return $this->role() !== self::ROLE_GUEST; }

    /** Account has completed email activation (or never required it). */
    public function isActive(): bool  { return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE; }
    public function isPending(): bool { return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_PENDING; }

    /** Rotate this value to make every existing cookie session fail the next auth check. */
    public function rotateAuthSessions(): void
    {
        $this->increment('auth_session_version');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ServiceSession::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedger::class);
    }

    public function tokenLedger(): HasMany
    {
        return $this->hasMany(TokenLedger::class);
    }

    public function tokenReservations(): HasMany
    {
        return $this->hasMany(TokenReservation::class);
    }

    public function subscriptionHistory(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class);
    }

    // --- Community platform (Domains layer) ------------------------------------
    // Contextual church roles, privacy preferences and presence. New community
    // tables live under app/Domains/*; relations point at their FQCNs.

    public function churchMemberships(): HasMany
    {
        return $this->hasMany(\App\Domains\Church\Models\ChurchMembership::class);
    }

    public function privacy(): HasOne
    {
        return $this->hasOne(\App\Domains\Accounts\Models\PrivacySetting::class);
    }

    public function presence(): HasOne
    {
        return $this->hasOne(\App\Domains\Accounts\Models\Presence::class);
    }

    /** Anonymous walk-ups live as @guest.local accounts; their billing tier is always guest. */
    public function isGuestAccount(): bool
    {
        return str_ends_with((string) $this->email, '@guest.local');
    }

    /**
     * The user's effective billing plan as an enum. Anonymous accounts are always
     * guest; otherwise the stored plan, defaulting to member. PlanService is the
     * single place that turns this into limits/features — callers should not branch
     * on the plan string directly.
     */
    public function plan(): \App\Enums\SubscriptionPlan
    {
        if ($this->isGuestAccount()) {
            return \App\Enums\SubscriptionPlan::GUEST;
        }

        return \App\Enums\SubscriptionPlan::tryFrom((string) $this->subscription_plan)
            ?? \App\Enums\SubscriptionPlan::MEMBER;
    }

    public function subscriptionStatus(): \App\Enums\SubscriptionStatus
    {
        return \App\Enums\SubscriptionStatus::tryFrom((string) $this->subscription_status)
            ?? \App\Enums\SubscriptionStatus::ACTIVE;
    }

    /** Premium entitlements apply only when on a paid plan AND the status grants access. */
    public function isPremium(): bool
    {
        return $this->plan()->isPaid() && $this->subscriptionStatus()->grantsAccess();
    }
}
