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
    /**
     * Interface locales that are NOT offered for AI vocabulary generation. Hebrew is a
     * Bible/reference locale only: the current model returns English for every Hebrew
     * concept (validated in Phase B), so showing it in the learner UI would mislead.
     * Re-enable by removing it here once a Hebrew-capable model/path exists.
     */
    public const NON_LEARNER_LANGUAGES = ['he'];

    /** Locales offered for learner generation: the interface registry minus the above. */
    public static function learnerLanguages(): array
    {
        return array_values(array_diff(\App\Models\Setting::LANGUAGES, self::NON_LEARNER_LANGUAGES));
    }

    protected $fillable = ['vocabulary_id', 'language', 'word', 'difficulty', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(Vocabulary::class);
    }
}
