<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Testimony extends Model
{
    protected $fillable = ['user_id', 'content', 'source', 'approved'];
    protected $casts = ['approved' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
