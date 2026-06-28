<?php

/*
 * AI Worship Radio — single source of truth for moods + language search config.
 *
 * The worshipper picks one of six universal moods (or types free text). The
 * `id` is language-independent and stable; only `labels` are translated. The UI
 * (/music/moods), the deterministic mood→concept expansion (MoodExpansionService),
 * and the per-language YouTube discovery queries (MusicRecommendationService) all
 * read from THIS file so there is one place to tune them.
 *
 * Adding a language: add an entry under `languages` (name + hint + broad
 * fallbacks) and a label per mood under each mood's `labels`. No code change.
 *
 * - emoji    : chip glyph.
 * - labels   : per-language display word. `en` is also the search anchor.
 *              (`my`/`td` reuse vetted native worship vocabulary; `td` is
 *              best-effort — correct here in one place if a word reads wrong.)
 * - concepts : richer spiritual tags the recommendation engine matches/searches
 *              on (scoring + YouTube query seeds). These are the real signal.
 * - triggers : free-text synonyms. When the worshipper types prose ("I'm
 *              anxious", "I miss my family") any trigger found routes to this
 *              mood; this also keeps PRE-EXISTING saved sessions working, whose
 *              stored mood keys (anxiety/peace/happy/lonely/…) are triggers here.
 */

return [

    'languages' => [
        'en' => [
            'name'     => 'English',
            'hint'     => 'English worship song',
            'fallback' => ['Christian worship song', 'praise and worship'],
        ],
        'my' => [
            'name'     => 'Burmese',
            'hint'     => 'Myanmar Burmese gospel worship song ဓမ္မသီချင်း',
            'fallback' => ['Myanmar worship song', 'Burmese gospel song', 'Myanmar Christian song'],
        ],
        'td' => [
            'name'     => 'Zolai',
            'hint'     => 'Tedim Zolai gospel worship song Pasian la',
            'fallback' => ['Zomi worship song', 'Tedim worship song', 'Pasian la Zomi'],
        ],
    ],

    'moods' => [

        'energy' => [
            'emoji'    => '⚡',
            'labels'   => ['en' => 'Energy', 'my' => 'ခွန်အား', 'td' => 'Hatna'],
            'concepts' => ['victory', 'praise', 'celebration', 'strength', 'revival', 'power'],
            'triggers' => [
                'energy', 'energize', 'energized', 'encourage', 'encouragement', 'strength',
                'strong', 'strengthen', 'power', 'powerful', 'victory', 'victorious', 'revival',
                'revive', 'praise', 'celebrate', 'celebration', 'motivated', 'overcome', 'weak',
            ],
        ],

        'feel_good' => [
            'emoji'    => '😊',
            'labels'   => ['en' => 'Feel Good', 'my' => 'ပျော်ရွှင်', 'td' => 'Lungdam'],
            'concepts' => ['joy', 'gratitude', 'thanksgiving', 'blessing', 'happiness'],
            'triggers' => [
                'happy', 'happiness', 'joy', 'joyful', 'glad', 'good', 'grateful', 'gratitude',
                'thankful', 'thanks', 'thanksgiving', 'blessed', 'blessing', 'cheerful', 'content', 'smile',
            ],
        ],

        'focus' => [
            'emoji'    => '🎯',
            'labels'   => ['en' => 'Focus', 'my' => 'အာရုံစူးစိုက်', 'td' => 'Ngaihsutna'],
            'concepts' => ['wisdom', 'guidance', 'devotion', 'study', 'meditation'],
            'triggers' => [
                'focus', 'focused', 'concentrate', 'study', 'studying', 'wisdom', 'guidance',
                'guide', 'devotion', 'devotional', 'meditate', 'meditation', 'learn', 'learning',
                'think', 'clarity', 'discipline', 'seek', 'seeking',
            ],
        ],

        'love' => [
            'emoji'    => '❤️',
            'labels'   => ['en' => 'Love', 'my' => 'ချစ်ခြင်းမေတ္တာ', 'td' => 'Itna'],
            'concepts' => ["god's love", 'grace', 'mercy', 'forgiveness', 'family', 'friendship'],
            'triggers' => [
                'love', 'loved', 'loving', 'family', 'friend', 'friends', 'friendship', 'marriage',
                'relationship', 'miss', 'missing', 'grace', 'mercy', 'forgive', 'forgiveness',
                'kindness', 'compassion',
            ],
        ],

        'relax' => [
            'emoji'    => '🌿',
            'labels'   => ['en' => 'Relax', 'my' => 'ငြိမ်သက်', 'td' => 'Lungmuanna'],
            'concepts' => ['peace', 'comfort', 'trust', 'hope', 'healing', 'anxiety', 'fear', 'rest'],
            'triggers' => [
                'relax', 'relaxed', 'peace', 'peaceful', 'calm', 'calmness', 'anxious', 'anxiety',
                'worried', 'worry', 'fear', 'afraid', 'scared', 'stress', 'stressed', 'rest', 'tired',
                'weary', 'sleep', 'hope', 'hopeful', 'trust', 'healing', 'heal', 'comfort', 'still',
                'quiet', 'angry', 'anger', 'pray', 'prayer',
            ],
        ],

        'heartbreak' => [
            'emoji'    => '💔',
            'labels'   => ['en' => 'Heartbreak', 'my' => 'နှလုံးကွဲ', 'td' => 'Lungtang kitan'],
            'concepts' => ['sadness', 'grief', 'loneliness', 'restoration', 'repentance', 'comfort', 'forgiveness'],
            'triggers' => [
                'heartbreak', 'heartbroken', 'broken', 'breakup', 'sad', 'sadness', 'unhappy', 'grief',
                'grieve', 'grieving', 'mourning', 'mourn', 'lonely', 'loneliness', 'alone', 'loss',
                'lost', 'hurt', 'hurting', 'depressed', 'depression', 'repent', 'repentance', 'sorrow',
                'cry', 'crying',
            ],
        ],

    ],
];
