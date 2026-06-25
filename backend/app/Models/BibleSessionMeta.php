<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BibleSessionMeta extends Model
{
    protected $table = 'bible_sessions';

    protected $fillable = [
        'chat_session_id', 'study_session_id', 'book', 'chapter', 'verses',
        'translation', 'discussion_summary',
    ];

    protected $casts = ['chapter' => 'integer'];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function studySession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'study_session_id');
    }
}
