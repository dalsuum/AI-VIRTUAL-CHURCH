<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialLedger extends Model
{
    protected $table = 'financial_ledger';
    protected $fillable = [
        'user_id', 'session_id', 'amount', 'currency', 'transaction_hash', 'allocation_type',
    ];
    protected $casts = ['amount' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
