<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable plan-transition record. Written by SubscriptionService. */
class SubscriptionHistory extends Model
{
    protected $table = 'subscription_history';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'user_id', 'old_plan', 'new_plan', 'reason', 'payment_ref',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
