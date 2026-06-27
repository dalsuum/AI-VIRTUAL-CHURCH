<?php

namespace App\Domains\Bible\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderSetting extends Model
{
    /** The configurable daily slots, in order. */
    public const SLOTS = ['morning', 'afternoon', 'evening'];

    /** Channels a user may choose; mapped to delivery channels by the notification. */
    public const CHANNELS = ['in_app', 'email', 'push'];

    protected $fillable = [
        'user_id', 'enabled', 'morning_at', 'afternoon_at', 'evening_at', 'timezone', 'channels',
    ];

    protected $casts = [
        'enabled'  => 'boolean',
        'channels' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The configured local time (H:i) for a slot, or null if unset. */
    public function slotTime(string $slot): ?string
    {
        $value = $this->{$slot.'_at'};

        return $value ? substr((string) $value, 0, 5) : null;
    }
}
