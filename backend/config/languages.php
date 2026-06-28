<?php

/*
 * Central language registry — the single source of truth for every interface
 * locale the platform supports. UI selectors (via GET /api/languages), the
 * SetLocale middleware, fav_language validation and locale-aware formatting all
 * consume this; do not hardcode language lists elsewhere.
 *
 * Note: this is the *interface* locale registry. The set of *Bible translations*
 * (which includes kjv/he and excludes UI-only locales) stays in
 * Setting::BIBLE_VERSIONS / bible_api._LANG_FILES — a related but distinct list.
 *
 * Per locale:
 *   native_name   — endonym shown in selectors
 *   english_name  — exonym for admin/English contexts
 *   rtl           — right-to-left script (layout direction)
 *   speech_locale — BCP-47 tag for speech recognition (STT)
 *   tts_locale    — BCP-47 tag for text-to-speech
 *   fallback      — locale to fall back to for missing strings
 *   enabled       — whether it is offered in the UI yet
 */

return [
    'fallback' => 'en',

    'list' => [
        'en'    => ['native_name' => 'English',  'english_name' => 'English',              'rtl' => false, 'speech_locale' => 'en-US', 'tts_locale' => 'en-US', 'fallback' => 'en', 'enabled' => true],
        'my'    => ['native_name' => 'ဗမာ',      'english_name' => 'Burmese',              'rtl' => false, 'speech_locale' => 'my-MM', 'tts_locale' => 'my-MM', 'fallback' => 'en', 'enabled' => true],
        // Tedim/Zolai: app-wide code is 'td' (intake, bible_api, fav_language,
        // PastorChat); ISO 639-3 is 'ctd'. Keep 'td' for consistency.
        'td'    => ['native_name' => 'Zomi',      'english_name' => 'Tedim (Zolai)',        'rtl' => false, 'speech_locale' => 'en-US', 'tts_locale' => 'en-US', 'fallback' => 'en', 'enabled' => true],
        'fr'    => ['native_name' => 'Français',  'english_name' => 'French',               'rtl' => false, 'speech_locale' => 'fr-FR', 'tts_locale' => 'fr-FR', 'fallback' => 'en', 'enabled' => true],
        'de'    => ['native_name' => 'Deutsch',   'english_name' => 'German',               'rtl' => false, 'speech_locale' => 'de-DE', 'tts_locale' => 'de-DE', 'fallback' => 'en', 'enabled' => true],
        'ja'    => ['native_name' => '日本語',     'english_name' => 'Japanese',             'rtl' => false, 'speech_locale' => 'ja-JP', 'tts_locale' => 'ja-JP', 'fallback' => 'en', 'enabled' => true],
        'zh-CN' => ['native_name' => '简体中文',   'english_name' => 'Chinese (Simplified)', 'rtl' => false, 'speech_locale' => 'zh-CN', 'tts_locale' => 'zh-CN', 'fallback' => 'en', 'enabled' => true],
        'hi'    => ['native_name' => 'हिन्दी',    'english_name' => 'Hindi',                'rtl' => false, 'speech_locale' => 'hi-IN', 'tts_locale' => 'hi-IN', 'fallback' => 'en', 'enabled' => true],
        'ko'    => ['native_name' => '한국어',     'english_name' => 'Korean',               'rtl' => false, 'speech_locale' => 'ko-KR', 'tts_locale' => 'ko-KR', 'fallback' => 'en', 'enabled' => true],
        'ar'    => ['native_name' => 'العربية',   'english_name' => 'Arabic',               'rtl' => true,  'speech_locale' => 'ar-SA', 'tts_locale' => 'ar-SA', 'fallback' => 'en', 'enabled' => true],
        'th'    => ['native_name' => 'ไทย',       'english_name' => 'Thai',                 'rtl' => false, 'speech_locale' => 'th-TH', 'tts_locale' => 'th-TH', 'fallback' => 'en', 'enabled' => true],
        'es'    => ['native_name' => 'Español',   'english_name' => 'Spanish',              'rtl' => false, 'speech_locale' => 'es-ES', 'tts_locale' => 'es-ES', 'fallback' => 'en', 'enabled' => true],
        'ta'    => ['native_name' => 'தமிழ்',     'english_name' => 'Tamil',                'rtl' => false, 'speech_locale' => 'ta-IN', 'tts_locale' => 'ta-IN', 'fallback' => 'en', 'enabled' => true],
    ],
];
