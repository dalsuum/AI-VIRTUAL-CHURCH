<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per token-balance mutation. Append-only; written only by TokenService inside
 * the balance-moving transaction. See the create_token_ledger_table migration.
 */
class TokenLedger extends Model
{
    protected $table = 'token_ledger';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'user_id', 'amount', 'balance_after', 'type', 'reference',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'balance_after' => 'integer',
        'type'          => \App\Enums\LedgerType::class,
        'created_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
