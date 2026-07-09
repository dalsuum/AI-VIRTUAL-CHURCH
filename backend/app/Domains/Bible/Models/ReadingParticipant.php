<?php

namespace App\Domains\Bible\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingParticipant extends Model
{
    protected $fillable = ['reading_session_id', 'user_id', 'user_reading_plan_id', 'joined_at'];

    protected $casts = ['joined_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReadingSession::class, 'reading_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The member's own plan enrollment — their progress in the session IS this row. */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(UserReadingPlan::class, 'user_reading_plan_id');
    }
}
