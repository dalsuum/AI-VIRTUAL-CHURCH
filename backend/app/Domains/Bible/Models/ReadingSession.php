<?php

namespace App\Domains\Bible\Models;

use App\Domains\Groups\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group reading a plan together. Status is read here but only ever WRITTEN by
 * ReadingSessionService. The session coordinates people; reading progress lives on
 * each participant's own user_reading_plans enrollment, never here.
 */
class ReadingSession extends Model
{
    use HasUuids;

    public const STATUS_PLANNED   = 'planned';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_ABANDONED];

    protected $fillable = [
        'id', 'correlation_id', 'group_id', 'reading_plan_id', 'created_by',
        'status', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ReadingPlan::class, 'reading_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ReadingParticipant::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
