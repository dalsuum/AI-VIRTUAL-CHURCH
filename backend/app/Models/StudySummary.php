<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The end-of-discussion artifacts. This (moderator-synthesized) summary is the only
 * model output allowed to enter long-term memory, keeping intermediate pastor
 * variations out of recall.
 */
class StudySummary extends Model
{
    protected $primaryKey = 'session_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'session_id', 'key_verses', 'lessons', 'prayer', 'action_points',
        'reflection_questions', 'study_plan', 'generated_at',
    ];

    protected $casts = [
        'key_verses'           => 'array',
        'lessons'              => 'array',
        'action_points'        => 'array',
        'reflection_questions' => 'array',
        'study_plan'           => 'array',
        'generated_at'         => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'session_id');
    }
}
