<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI-generated learner entry: one curated {@see Vocabulary} concept rendered into
 * one language. A cache — populated on demand by the history worker (vocab_generate),
 * never hand-authored. `payload` is null while generation is in flight.
 */
class VocabEntry extends Model
{
    protected $fillable = ['vocabulary_id', 'language', 'word', 'difficulty', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(Vocabulary::class);
    }
}
