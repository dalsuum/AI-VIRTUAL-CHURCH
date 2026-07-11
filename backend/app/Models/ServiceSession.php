<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ServiceSession extends Model
{
    public const RESUME_SESSION_ID_KEY = 'service_resume_session_id';

    protected $fillable = [
        'user_id', 'group_id', 'session_token', 'resume_token_hash', 'resume_token_expires_at',
        'resume_token_used_at', 'status', 'music_source', 'language', 'tedim_status',
        'burmese_status', 'presenter_gender', 'scheduled_at', 'contact_email',
        'started_at', 'ended_at',
    ];
    protected $casts = [
        'resume_token_expires_at' => 'datetime',
        'resume_token_used_at'    => 'datetime',
        'scheduled_at'            => 'datetime',
        'started_at'              => 'datetime',
        'ended_at'                => 'datetime',
    ];

    public function issueResumeToken(?Carbon $expiresAt = null): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'resume_token_hash'       => hash('sha256', $token),
            'resume_token_expires_at' => $expiresAt ?? $this->defaultResumeTokenExpiry(),
            'resume_token_used_at'    => null,
        ])->save();

        return $token;
    }

    public function defaultResumeTokenExpiry(): Carbon
    {
        $base = $this->scheduled_at && $this->scheduled_at->isFuture()
            ? $this->scheduled_at->copy()
            : now();

        return $base->addHours(24);
    }

    public function consumeResumeToken(): void
    {
        $this->forceFill([
            'resume_token_hash'       => null,
            'resume_token_expires_at' => null,
            'resume_token_used_at'    => now(),
        ])->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function intake(): HasOne
    {
        return $this->hasOne(ServiceIntake::class, 'session_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ServiceAsset::class, 'session_id');
    }
}
