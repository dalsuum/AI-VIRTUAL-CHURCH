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
        'fr' => [
            'name'     => 'French',
            'hint'     => 'chant de louange chrétien français',
            'fallback' => ['louange chrétienne française', 'chant chrétien français', 'adoration chrétienne française'],
        ],
        'de' => [
            'name'     => 'German',
            'hint'     => 'deutsches christliches Lobpreislied',
            'fallback' => ['deutsche Lobpreismusik', 'christliche Anbetung deutsch', 'deutsche christliche Lieder'],
        ],
        'es' => [
            'name'     => 'Spanish',
            'hint'     => 'canción cristiana de adoración en español',
            'fallback' => ['alabanza cristiana en español', 'música cristiana en español', 'adoración cristiana español'],
        ],
    ],

    'moods' => [

        'energy' => [
            'emoji'    => '⚡',
            'labels'   => ['en' => 'Energy', 'my' => 'ခွန်အား', 'td' => 'Hatna', 'fr' => 'Énergie', 'de' => 'Energie', 'es' => 'Energía'],
            'concepts' => ['victory', 'praise', 'celebration', 'strength', 'revival', 'power'],
            'triggers' => [
                'energy', 'energize', 'energized', 'encourage', 'encouragement', 'strength',
                'strong', 'strengthen', 'power', 'powerful', 'victory', 'victorious', 'revival',
                'revive', 'praise', 'celebrate', 'celebration', 'motivated', 'overcome', 'weak',
                'énergie', 'force', 'courage', 'encouragement', 'victoire',
                'energie', 'kraft', 'ermutigung', 'sieg', 'stärke',
                'energía', 'energia', 'fuerza', 'ánimo', 'animo', 'victoria',
            ],
        ],

        'feel_good' => [
            'emoji'    => '😊',
            'labels'   => ['en' => 'Feel Good', 'my' => 'ပျော်ရွှင်', 'td' => 'Lungdam', 'fr' => 'Joie', 'de' => 'Freude', 'es' => 'Alegría'],
            'concepts' => ['joy', 'gratitude', 'thanksgiving', 'blessing', 'happiness'],
            'triggers' => [
                'happy', 'happiness', 'joy', 'joyful', 'glad', 'good', 'grateful', 'gratitude',
                'thankful', 'thanks', 'thanksgiving', 'blessed', 'blessing', 'cheerful', 'content', 'smile',
                'joie', 'joyeux', 'heureux', 'reconnaissant', 'merci', 'bénédiction',
                'freude', 'fröhlich', 'dankbar', 'danke', 'segen', 'glücklich',
                'alegría', 'alegria', 'feliz', 'agradecido', 'gracias', 'bendición', 'bendicion',
            ],
        ],

        'focus' => [
            'emoji'    => '🎯',
            'labels'   => ['en' => 'Focus', 'my' => 'အာရုံစူးစိုက်', 'td' => 'Ngaihsutna', 'fr' => 'Concentration', 'de' => 'Fokus', 'es' => 'Enfoque'],
            'concepts' => ['wisdom', 'guidance', 'devotion', 'study', 'meditation'],
            'triggers' => [
                'focus', 'focused', 'concentrate', 'study', 'studying', 'wisdom', 'guidance',
                'guide', 'devotion', 'devotional', 'meditate', 'meditation', 'learn', 'learning',
                'think', 'clarity', 'discipline', 'seek', 'seeking',
                'concentration', 'sagesse', 'guider', 'méditation', 'chercher',
                'fokus', 'weisheit', 'führung', 'andacht', 'lernen', 'suchen',
                'enfoque', 'sabiduría', 'sabiduria', 'guía', 'guia', 'devoción', 'devocion', 'buscar',
            ],
        ],

        'love' => [
            'emoji'    => '❤️',
            'labels'   => ['en' => 'Love', 'my' => 'ချစ်ခြင်းမေတ္တာ', 'td' => 'Itna', 'fr' => 'Amour', 'de' => 'Liebe', 'es' => 'Amor'],
            'concepts' => ["god's love", 'grace', 'mercy', 'forgiveness', 'family', 'friendship'],
            'triggers' => [
                'love', 'loved', 'loving', 'family', 'friend', 'friends', 'friendship', 'marriage',
                'relationship', 'miss', 'missing', 'grace', 'mercy', 'forgive', 'forgiveness',
                'kindness', 'compassion',
                'amour', 'famille', 'ami', 'grâce', 'grace', 'miséricorde', 'pardon',
                'liebe', 'familie', 'freund', 'gnade', 'barmherzigkeit', 'vergebung',
                'amor', 'familia', 'amigo', 'gracia', 'misericordia', 'perdón', 'perdon',
            ],
        ],

        'relax' => [
            'emoji'    => '🌿',
            'labels'   => ['en' => 'Relax', 'my' => 'ငြိမ်သက်', 'td' => 'Lungmuanna', 'fr' => 'Paix', 'de' => 'Ruhe', 'es' => 'Paz'],
            'concepts' => ['peace', 'comfort', 'trust', 'hope', 'healing', 'anxiety', 'fear', 'rest'],
            'triggers' => [
                'relax', 'relaxed', 'peace', 'peaceful', 'calm', 'calmness', 'anxious', 'anxiety',
                'worried', 'worry', 'fear', 'afraid', 'scared', 'stress', 'stressed', 'rest', 'tired',
                'weary', 'sleep', 'hope', 'hopeful', 'trust', 'healing', 'heal', 'comfort', 'still',
                'quiet', 'angry', 'anger', 'pray', 'prayer',
                'paix', 'calme', 'anxieux', 'inquiet', 'repos', 'espérance', 'esperance', 'guérison', 'guerison', 'prière',
                'ruhe', 'frieden', 'ängstlich', 'angst', 'sorge', 'hoffnung', 'vertrauen', 'heilung', 'gebet',
                'paz', 'tranquilo', 'ansioso', 'ansiedad', 'preocupado', 'descanso', 'esperanza', 'sanidad', 'oración', 'oracion',
            ],
        ],

        'heartbreak' => [
            'emoji'    => '💔',
            'labels'   => ['en' => 'Heartbreak', 'my' => 'နှလုံးကွဲ', 'td' => 'Lungtang kitan', 'fr' => 'Peine', 'de' => 'Herzschmerz', 'es' => 'Quebranto'],
            'concepts' => ['sadness', 'grief', 'loneliness', 'restoration', 'repentance', 'comfort', 'forgiveness'],
            'triggers' => [
                'heartbreak', 'heartbroken', 'broken', 'breakup', 'sad', 'sadness', 'unhappy', 'grief',
                'grieve', 'grieving', 'mourning', 'mourn', 'lonely', 'loneliness', 'alone', 'loss',
                'lost', 'hurt', 'hurting', 'depressed', 'depression', 'repent', 'repentance', 'sorrow',
                'cry', 'crying',
                'peine', 'triste', 'deuil', 'seul', 'solitude', 'perte', 'blessé', 'repentance',
                'herzschmerz', 'traurig', 'trauer', 'einsam', 'verlust', 'verletzt', 'buße',
                'quebranto', 'triste', 'duelo', 'solo', 'soledad', 'pérdida', 'perdida', 'herido', 'arrepentimiento',
            ],
        ],

    ],
];
