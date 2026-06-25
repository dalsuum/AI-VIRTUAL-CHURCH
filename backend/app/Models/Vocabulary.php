<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Zolai (Tedim Chin) reference word with its Burmese and English glosses,
 * shown on the public #vocabulary page and edited from the admin Vocabulary tab.
 */
class Vocabulary extends Model
{
    /** Laravel would guess "vocabularys"; pin the correct plural. */
    protected $table = 'vocabularies';

    /** Ethnic-language gloss columns offered in the public language dropdown (default Zolai). */
    public const LANGUAGE_COLUMNS = ['zolai', 'falam', 'hakha', 'matu', 'mizo', 'paite', 'sizang', 'burmese', 'hebrew', 'english'];

    protected $fillable = [
        'zolai', 'falam', 'hakha', 'matu', 'mizo', 'paite', 'sizang',
        'burmese', 'hebrew', 'english', 'category', 'source',
    ];
}
