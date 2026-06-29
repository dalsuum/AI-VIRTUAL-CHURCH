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
        'ja' => [
            'name'     => 'Japanese',
            'hint'     => '日本語のキリスト教礼拝賛美歌',
            'fallback' => ['日本語 賛美歌', 'キリスト教 礼拝 日本語', '日本語 ワーシップ'],
        ],
        'zh-CN' => [
            'name'     => 'Chinese (Simplified)',
            'hint'     => '中文基督教敬拜歌曲',
            'fallback' => ['中文赞美诗歌', '基督教敬拜 中文', '中文敬拜赞美'],
        ],
        'ko' => [
            'name'     => 'Korean',
            'hint'     => '한국어 기독교 예배 찬양',
            'fallback' => ['한국어 찬양', '기독교 예배 찬양', '한국어 워십 찬양'],
        ],
        'hi' => [
            'name'     => 'Hindi',
            'hint'     => 'हिंदी मसीही आराधना गीत',
            'fallback' => ['हिंदी आराधना गीत', 'हिंदी मसीही भजन', 'Hindi Christian worship song'],
        ],
        'ta' => [
            'name'     => 'Tamil',
            'hint'     => 'தமிழ் கிறிஸ்தவ ஆராதனை பாடல்',
            'fallback' => ['தமிழ் ஆராதனை பாடல்', 'தமிழ் கிறிஸ்தவ பாடல்', 'Tamil Christian worship song'],
        ],
        'th' => [
            'name'     => 'Thai',
            'hint'     => 'เพลงนมัสการคริสเตียนภาษาไทย',
            'fallback' => ['เพลงคริสเตียนไทย', 'เพลงสรรเสริญพระเจ้า ภาษาไทย', 'Thai Christian worship song'],
        ],
        'ar' => [
            'name'     => 'Arabic',
            'hint'     => 'ترانيم عبادة مسيحية عربية',
            'fallback' => ['ترانيم مسيحية عربية', 'ترانيم عبادة عربية', 'Arabic Christian worship song'],
        ],
        'he' => [
            'name'     => 'Hebrew',
            'hint'     => 'שירי הלל נוצריים בעברית',
            'fallback' => ['שירי הלל נוצריים בעברית', 'שירי פולחן נוצריים בעברית', 'Hebrew Christian worship song'],
        ],
    ],

    'moods' => [

        'energy' => [
            'emoji'    => '⚡',
            'labels'   => ['en' => 'Energy', 'my' => 'ခွန်အား', 'td' => 'Hatna', 'fr' => 'Énergie', 'de' => 'Energie', 'es' => 'Energía', 'ja' => '力', 'zh-CN' => '力量', 'ko' => '힘', 'hi' => 'बल', 'ta' => 'வல்லமை', 'th' => 'กำลัง', 'ar' => 'قوة', 'he' => 'כוח'],
            'concepts' => ['victory', 'praise', 'celebration', 'strength', 'revival', 'power'],
            'triggers' => [
                'energy', 'energize', 'energized', 'encourage', 'encouragement', 'strength',
                'strong', 'strengthen', 'power', 'powerful', 'victory', 'victorious', 'revival',
                'revive', 'praise', 'celebrate', 'celebration', 'motivated', 'overcome', 'weak',
                'mighty', 'unstoppable', 'bold', 'breakthrough', 'fired up', 'upbeat',
                'énergie', 'force', 'courage', 'victoire',
                'energie', 'kraft', 'ermutigung', 'sieg', 'stärke',
                'energía', 'energia', 'fuerza', 'ánimo', 'animo', 'victoria',
                '力', '励まし', '勝利', '元気', 'リバイバル',
                '力量', '鼓励', '得胜', '复兴', '刚强',
                '힘', '격려', '승리', '부흥', '강하게',
                'बल', 'शक्ति', 'हिम्मत', 'विजय', 'उत्साह',
                'வல்லமை', 'பெலம்', 'ஊக்கம்', 'வெற்றி',
                'กำลัง', 'หนุนใจ', 'ชัยชนะ', 'ฟื้นฟู',
                'قوة', 'تشجيع', 'انتصار', 'نهضة',
                'כוח', 'עידוד', 'ניצחון', 'התחדשות',
            ],
        ],

        'feel_good' => [
            'emoji'    => '😊',
            'labels'   => ['en' => 'Feel Good', 'my' => 'ပျော်ရွှင်', 'td' => 'Lungdam', 'fr' => 'Joie', 'de' => 'Freude', 'es' => 'Alegría', 'ja' => '喜び', 'zh-CN' => '喜乐', 'ko' => '기쁨', 'hi' => 'आनंद', 'ta' => 'மகிழ்ச்சி', 'th' => 'ความยินดี', 'ar' => 'فرح', 'he' => 'שמחה'],
            'concepts' => ['joy', 'gratitude', 'thanksgiving', 'blessing', 'happiness'],
            'triggers' => [
                'happy', 'happiness', 'joy', 'joyful', 'glad', 'good', 'grateful', 'gratitude',
                'thankful', 'thanks', 'thanksgiving', 'blessed', 'blessing', 'cheerful', 'content', 'smile',
                'rejoice', 'rejoicing', 'delight', 'merry', 'thank you',
                'joie', 'joyeux', 'heureux', 'reconnaissant', 'merci', 'bénédiction',
                'freude', 'fröhlich', 'dankbar', 'danke', 'segen', 'glücklich',
                'alegría', 'alegria', 'feliz', 'agradecido', 'gracias', 'bendición', 'bendicion',
                '喜び', '感謝', '祝福', 'うれしい',
                '喜乐', '感谢', '开心',
                '기쁨', '감사', '축복', '즐거움',
                'आनंद', 'खुशी', 'धन्यवाद', 'आशीष',
                'மகிழ்ச்சி', 'நன்றி', 'ஆசீர்வாதம்',
                'ความยินดี', 'ขอบคุณ', 'พระพร', 'สุขใจ',
                'فرح', 'شكر', 'بركة', 'مبارك',
                'שמחה', 'תודה', 'ברכה', 'מבורך',
            ],
        ],

        'focus' => [
            'emoji'    => '🎯',
            'labels'   => ['en' => 'Focus', 'my' => 'အာရုံစူးစိုက်', 'td' => 'Ngaihsutna', 'fr' => 'Concentration', 'de' => 'Fokus', 'es' => 'Enfoque', 'ja' => '集中', 'zh-CN' => '专注', 'ko' => '집중', 'hi' => 'ध्यान', 'ta' => 'கவனம்', 'th' => 'สมาธิ', 'ar' => 'تركيز', 'he' => 'מיקוד'],
            'concepts' => ['wisdom', 'guidance', 'devotion', 'study', 'meditation'],
            'triggers' => [
                'focus', 'focused', 'concentrate', 'study', 'studying', 'wisdom', 'guidance',
                'guide', 'devotion', 'devotional', 'meditate', 'meditation', 'learn', 'learning',
                'think', 'clarity', 'discipline', 'seek', 'seeking',
                'meditative', 'reflect', 'reflection', 'ponder', 'quiet time', 'instrumental',
                'concentration', 'sagesse', 'guider', 'méditation', 'chercher',
                'fokus', 'weisheit', 'führung', 'andacht', 'lernen', 'suchen',
                'enfoque', 'sabiduría', 'sabiduria', 'guía', 'guia', 'devoción', 'devocion', 'buscar',
                '集中', '知恵', '導き', '学び', '黙想',
                '专注', '智慧', '引导', '学习', '灵修',
                '집중', '지혜', '인도', '묵상', '배움',
                'ध्यान', 'बुद्धि', 'मार्गदर्शन', 'अध्ययन',
                'கவனம்', 'ஞானம்', 'வழிநடத்தல்', 'தியானம்',
                'สมาธิ', 'ปัญญา', 'การนำ', 'ใคร่ครวญ',
                'تركيز', 'حكمة', 'إرشاد', 'تأمل',
                'מיקוד', 'חכמה', 'הדרכה', 'לימוד', 'הגות',
            ],
        ],

        'love' => [
            'emoji'    => '❤️',
            'labels'   => ['en' => 'Love', 'my' => 'ချစ်ခြင်းမေတ္တာ', 'td' => 'Itna', 'fr' => 'Amour', 'de' => 'Liebe', 'es' => 'Amor', 'ja' => '愛', 'zh-CN' => '爱', 'ko' => '사랑', 'hi' => 'प्रेम', 'ta' => 'அன்பு', 'th' => 'ความรัก', 'ar' => 'محبة', 'he' => 'אהבה'],
            'concepts' => ["god's love", 'grace', 'mercy', 'forgiveness', 'family', 'friendship'],
            'triggers' => [
                'love', 'loved', 'loving', 'family', 'friend', 'friends', 'friendship', 'marriage',
                'relationship', 'miss', 'missing', 'grace', 'mercy', 'forgive', 'forgiveness',
                'kindness', 'compassion',
                'beloved', 'cherish', 'affection', 'devoted', 'tenderness', 'wedding',
                'amour', 'famille', 'ami', 'grâce', 'miséricorde', 'pardon',
                'liebe', 'familie', 'freund', 'gnade', 'barmherzigkeit', 'vergebung',
                'amor', 'familia', 'amigo', 'gracia', 'misericordia', 'perdón', 'perdon',
                '愛', '家族', '友', '恵み', '赦し',
                '爱', '家庭', '朋友', '恩典', '怜悯', '饶恕',
                '사랑', '가족', '친구', '은혜', '용서',
                'प्रेम', 'परिवार', 'अनुग्रह', 'दया', 'क्षमा',
                'அன்பு', 'குடும்பம்', 'கிருபை', 'இரக்கம்', 'மன்னிப்பு',
                'ความรัก', 'ครอบครัว', 'พระคุณ', 'เมตตา', 'การให้อภัย',
                'محبة', 'عائلة', 'نعمة', 'رحمة', 'غفران',
                'אהבה', 'משפחה', 'חסד', 'רחמים', 'סליחה',
            ],
        ],

        'relax' => [
            'emoji'    => '🌿',
            'labels'   => ['en' => 'Relax', 'my' => 'ငြိမ်သက်', 'td' => 'Lungmuanna', 'fr' => 'Paix', 'de' => 'Ruhe', 'es' => 'Paz', 'ja' => '平安', 'zh-CN' => '平安', 'ko' => '평안', 'hi' => 'शांति', 'ta' => 'சமாதானம்', 'th' => 'สันติสุข', 'ar' => 'سلام', 'he' => 'שלום'],
            'concepts' => ['peace', 'comfort', 'trust', 'hope', 'healing', 'anxiety', 'fear', 'rest'],
            'triggers' => [
                'relax', 'relaxed', 'peace', 'peaceful', 'calm', 'calmness', 'anxious', 'anxiety',
                'worried', 'worry', 'fear', 'afraid', 'scared', 'stress', 'stressed', 'rest', 'tired',
                'weary', 'sleep', 'hope', 'hopeful', 'trust', 'healing', 'heal', 'comfort', 'still',
                'quiet', 'angry', 'anger', 'pray', 'prayer',
                'serene', 'serenity', 'soothe', 'soothing', 'unwind', 'breathe', 'overwhelmed', 'burnout',
                'paix', 'calme', 'anxieux', 'inquiet', 'repos', 'espérance', 'esperance', 'guérison', 'guerison', 'prière',
                'ruhe', 'frieden', 'ängstlich', 'angst', 'sorge', 'hoffnung', 'vertrauen', 'heilung', 'gebet',
                'paz', 'tranquilo', 'ansioso', 'ansiedad', 'preocupado', 'descanso', 'esperanza', 'sanidad', 'oración', 'oracion',
                '平安', '安心', '祈り', '休み', '癒し', '希望',
                '安静', '祷告', '休息', '医治', '盼望',
                '평안', '안식', '기도', '치유', '소망', '두려움',
                'शांति', 'विश्राम', 'आशा', 'चंगाई', 'प्रार्थना',
                'சமாதானம்', 'இளைப்பாறுதல்', 'நம்பிக்கை', 'சுகம்', 'ஜெபம்',
                'สันติสุข', 'พักสงบ', 'ความหวัง', 'การรักษา', 'อธิษฐาน',
                'سلام', 'راحة', 'رجاء', 'شفاء', 'صلاة', 'قلق', 'خوف',
                'שלום', 'מנוחה', 'תקווה', 'ריפוי', 'תפילה', 'חרדה', 'פחד',
            ],
        ],

        'heartbreak' => [
            'emoji'    => '💔',
            'labels'   => ['en' => 'Heartbreak', 'my' => 'နှလုံးကွဲ', 'td' => 'Lungtang kitan', 'fr' => 'Peine', 'de' => 'Herzschmerz', 'es' => 'Quebranto', 'ja' => '悲しみ', 'zh-CN' => '伤心', 'ko' => '상심', 'hi' => 'दुःख', 'ta' => 'துயரம்', 'th' => 'ความเสียใจ', 'ar' => 'حزن', 'he' => 'כאב לב'],
            'concepts' => ['sadness', 'grief', 'loneliness', 'restoration', 'repentance', 'comfort', 'forgiveness'],
            'triggers' => [
                'heartbreak', 'heartbroken', 'broken', 'breakup', 'sad', 'sadness', 'unhappy', 'grief',
                'grieve', 'grieving', 'mourning', 'mourn', 'lonely', 'loneliness', 'alone', 'loss',
                'lost', 'hurt', 'hurting', 'depressed', 'depression', 'repent', 'repentance', 'sorrow',
                'cry', 'crying',
                'sorrowful', 'heartache', 'despair', 'grieved', 'tears', 'betrayed', 'rejected',
                'peine', 'triste', 'deuil', 'seul', 'solitude', 'perte', 'blessé', 'repentir',
                'herzschmerz', 'traurig', 'trauer', 'einsam', 'verlust', 'verletzt', 'buße',
                'quebranto', 'duelo', 'solo', 'soledad', 'pérdida', 'perdida', 'herido', 'arrepentimiento',
                '悲しみ', '孤独', '喪失', '傷ついた', '悔い改め',
                '伤心', '悲伤', '失去', '悔改',
                '상심', '슬픔', '외로움', '상처', '회개',
                'दुःख', 'शोक', 'अकेलापन', 'टूटा', 'पश्चाताप',
                'துயரம்', 'துக்கம்', 'தனிமை', 'மனமுடைவு', 'மனந்திரும்புதல்',
                'ความเสียใจ', 'เศร้า', 'โดดเดี่ยว', 'บาดเจ็บ', 'กลับใจ',
                'حزن', 'ألم', 'وحدة', 'توبة', 'جريح',
                'כאב', 'עצב', 'בדידות', 'חרטה', 'פצוע',
            ],
        ],

    ],
];
