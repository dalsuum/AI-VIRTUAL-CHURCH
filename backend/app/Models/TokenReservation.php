<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Two-phase token hold. Managed by TokenService; see the migration for the lifecycle. */
class TokenReservation extends Model
{
    public $timestamps = false; // created_at + resolved_at managed explicitly

    public const PENDING     = 'pending';
    public const COMMITTED   = 'committed';
    public const ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'user_id', 'amount', 'service', 'status', 'reference', 'expires_at', 'resolved_at',
    ];

    protected $casts = [
        'amount'      => 'integer',
        'expires_at'  => 'datetime',
        'created_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
