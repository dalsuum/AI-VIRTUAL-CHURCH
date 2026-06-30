<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worshipper's saved ('favorite') or recently-viewed ('viewed') vocabulary concept —
 * the only per-user vocabulary state. Generated entries/explanations are cached globally
 * on {@see VocabEntry}, never duplicated per user.
 */
class UserVocabulary extends Model
{
    protected $table = 'user_vocabulary';

    public const KIND_FAVORITE = 'favorite';
    public const KIND_VIEWED = 'viewed';

    protected $fillable = ['user_id', 'vocabulary_id', 'kind'];

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(Vocabulary::class);
    }
}
