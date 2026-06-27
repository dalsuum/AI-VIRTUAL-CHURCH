<?php

namespace App\Domains\Invitations\Models;

use App\Enums\InvitationActivity;
use App\Enums\InvitationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One invitation for any together-activity. Status is read here but only ever WRITTEN
 * by InvitationService. invitable points at the session created on accept (set in a
 * later phase when session types exist).
 */
class Invitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'correlation_id', 'inviter_id', 'invitee_id', 'activity',
        'invitable_type', 'invitable_id', 'status', 'scheduled_at', 'timezone',
        'recurrence', 'message', 'expires_at', 'responded_at',
    ];

    protected $casts = [
        'activity'     => InvitationActivity::class,
        'status'       => InvitationStatus::class,
        'recurrence'   => 'array',
        'scheduled_at' => 'datetime',
        'expires_at'   => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    public function invitable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::PENDING;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
