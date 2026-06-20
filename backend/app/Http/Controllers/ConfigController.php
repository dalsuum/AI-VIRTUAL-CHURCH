<?php

namespace App\Http\Controllers;

use App\Models\ServiceIntake;
use App\Models\Setting;
use App\Models\Testimony;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public, unauthenticated app configuration consumed by the intake form before a
 * worshipper has a session: which moods to offer, which music sources are enabled,
 * and whether scheduling is available. Admins shape these via the admin settings.
 */
class ConfigController extends Controller
{
    /** Mood keyword → curated verse references (English, looked up in the active language Bible). */
    private const MOOD_VERSES = [
        'grateful'  => ['Psalm 100:4', 'Colossians 3:17', '1 Thessalonians 5:18', 'Psalm 107:1'],
        'thankful'  => ['Psalm 100:4', 'Colossians 3:17', '1 Thessalonians 5:18'],
        'anxious'   => ['Philippians 4:6', 'Matthew 6:34', '1 Peter 5:7', 'Isaiah 41:10', 'Psalm 23:4'],
        'worried'   => ['Philippians 4:6', 'Matthew 6:34', '1 Peter 5:7'],
        'stressed'  => ['Philippians 4:6', '1 Peter 5:7', 'Matthew 11:28'],
        'grieving'  => ['Psalm 34:18', 'Matthew 5:4', 'Revelation 21:4', 'Psalm 147:3'],
        'grief'     => ['Psalm 34:18', 'Matthew 5:4', 'Psalm 147:3'],
        'sad'       => ['Psalm 34:18', 'Psalm 147:3', 'Isaiah 61:1'],
        'lonely'    => ['Psalm 34:18', 'Hebrews 13:5', 'Isaiah 41:10'],
        'lost'      => ['Matthew 7:7', 'Isaiah 43:1', 'Psalm 23:1'],
        'joyful'    => ['Psalm 16:11', 'Philippians 4:4', 'Psalm 118:24', 'Zephaniah 3:17'],
        'joy'       => ['Psalm 16:11', 'Philippians 4:4', 'Psalm 118:24'],
        'happy'     => ['Psalm 16:11', 'Philippians 4:4', 'Psalm 118:24'],
        'celebrat'  => ['Psalm 118:24', 'Zephaniah 3:17', 'Philippians 4:4'],
        'seeking'   => ['Matthew 7:7', 'Jeremiah 29:13', 'James 4:8', 'Proverbs 3:5'],
        'search'    => ['Matthew 7:7', 'Jeremiah 29:13', 'James 4:8'],
        'hopeful'   => ['Jeremiah 29:11', 'Romans 15:13', 'Hebrews 11:1', 'Lamentations 3:22'],
        'hope'      => ['Jeremiah 29:11', 'Romans 15:13', 'Hebrews 11:1'],
        'desperate' => ['Psalm 46:1', 'Isaiah 40:31', 'Romans 8:28', 'Psalm 34:18'],
        'tired'     => ['Matthew 11:28', 'Isaiah 40:31', 'Psalm 62:1'],
        'weary'     => ['Matthew 11:28', 'Isaiah 40:31', 'Psalm 62:1'],
        'broken'    => ['Psalm 34:18', 'Isaiah 61:1', 'Psalm 147:3'],
        'peace'     => ['John 14:27', 'Philippians 4:7', 'Isaiah 26:3'],
        'peaceful'  => ['John 14:27', 'Philippians 4:7', 'Isaiah 26:3'],
        'confused'  => ['Proverbs 3:5', 'James 1:5', 'John 14:6'],
        'doubt'     => ['Hebrews 11:1', 'Mark 9:24', 'James 1:6'],
        'worship'   => ['Psalm 95:6', 'Psalm 100:2', 'John 4:24'],
        'praise'    => ['Psalm 100:4', 'Psalm 95:6', 'Psalm 118:24'],
        'fear'      => ['Isaiah 41:10', 'Psalm 23:4', '2 Timothy 1:7', 'Psalm 46:1'],
        'afraid'    => ['Isaiah 41:10', 'Psalm 23:4', '2 Timothy 1:7'],
        'angry'     => ['Ephesians 4:26', 'James 1:19', 'Psalm 55:22'],
        'anger'     => ['Ephesians 4:26', 'James 1:19', 'Psalm 62:1'],
        'forgiv'    => ['Colossians 3:13', '1 John 1:9', 'Matthew 6:14'],
        'heal'      => ['Psalm 147:3', 'Isaiah 53:5', 'James 5:16'],
        'strength'  => ['Isaiah 40:31', 'Philippians 4:13', 'Psalm 46:1'],
        'strong'    => ['Isaiah 40:31', 'Philippians 4:13', 'Joshua 1:9'],
    ];

    private const DEFAULT_VERSES = [
        'Psalm 23:1', 'John 3:16', 'Romans 8:28', 'Psalm 46:1',
        'Isaiah 40:31', 'Matthew 11:28', 'Jeremiah 29:11', 'Philippians 4:13',
    ];

    public function show(Request $request): JsonResponse
    {
        $language = in_array($request->query('language'), ['en', 'my', 'td'], true)
            ? $request->query('language')
            : 'en';
        $mood = trim((string) $request->query('mood', ''));
        $mood = mb_substr($mood, 0, 100);

        return response()->json([
            'moods'                   => Setting::moods(),
            'music_sources'           => Setting::enabledMusicSources(),
            'scheduling_enabled'      => Setting::schedulingEnabled(),
            'enabled_languages'       => Setting::enabledLanguages(),
            'countdown_cards'         => $this->countdownCards($mood, $language),
            'content_filter_keywords' => Setting::filterKeywords(),
            'content_filter_music'    => Setting::filterKeywordsForScope('music'),
            'content_filter_sermon'   => Setting::filterKeywordsForScope('sermon'),
            'content_filter_allow_music'  => Setting::allowKeywordsForScope('music'),
            'content_filter_allow_sermon' => Setting::allowKeywordsForScope('sermon'),
        ]);
    }

    /** Cards shown during the preparation countdown. Public endpoint, text only. */
    private function countdownCards(string $mood = '', string $language = 'en'): array
    {
        if (Setting::get('countdown_content_enabled', '1') !== '1') {
            return [];
        }

        $source = Setting::get('countdown_content_source', 'both');
        if (! in_array($source, ['banners', 'testimonies', 'verses', 'both', 'all'], true)) {
            return [];
        }

        $cards = [];

        // Admin banners are written in English — only show them for English services
        // so non-English services don't receive mixed-language slides.
        if ($language === 'en' && in_array($source, ['banners', 'both', 'all'], true)) {
            foreach (Setting::countdownBanners() as $banner) {
                $cards[] = [
                    'type' => 'banner',
                    'text' => $banner['text'],
                    'source' => $banner['source'],
                ];
            }
        }

        // Non-English services always get mood-matched Scripture in the service language.
        // English services get them when the source explicitly includes verses.
        if ($language !== 'en' || in_array($source, ['verses', 'all'], true)) {
            foreach ($this->moodBibleVerseCards($mood, $language) as $card) {
                $cards[] = $card;
            }
        }

        if (in_array($source, ['testimonies', 'both', 'all'], true)) {
            $testimonies = $this->relatedTestimonies($mood, $language);

            foreach ($testimonies as $testimony) {
                $cards[] = [
                    'type' => 'testimony',
                    'text' => mb_substr($testimony->content, 0, 420),
                    'source' => 'Shared testimony',
                ];
            }
        }

        shuffle($cards);

        return array_slice($cards, 0, 16);
    }

    /** Approved testimonies from worshippers who have used the same mood/language. */
    private function relatedTestimonies(string $mood, string $language)
    {
        if ($mood === '') {
            return collect();
        }

        $userIds = ServiceIntake::query()
            ->join('service_sessions', 'service_sessions.id', '=', 'service_intakes.session_id')
            ->where('service_sessions.language', $language)
            ->where(function ($q) use ($mood) {
                $q->where('service_intakes.mood', $mood)
                    ->orWhere('service_intakes.custom_mood', $mood);
            })
            ->whereNotNull('service_sessions.user_id')
            ->select('service_sessions.user_id');

        return Testimony::where('approved', true)
            ->whereIn('user_id', $userIds)
            ->inRandomOrder()
            ->limit(10)
            ->get(['content']);
    }

    /** Mood-matched Bible verse cards from local bundled translations. */
    private function moodBibleVerseCards(string $mood, string $language): array
    {
        $refs = $this->verseRefsForMood($mood);
        sort($refs);

        $cacheKey = 'countdown_verse_' . $language . '_' . substr(md5(implode('|', $refs)), 0, 16);

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($refs, $language) {
            $file = match ($language) {
                'my'    => base_path('../workers/data/judson1835.json'),
                'td'    => base_path('../workers/data/tedim1932.json'),
                default => base_path('../workers/data/bsb.json'),
            };

            if (! is_readable($file)) {
                return [];
            }

            $bible     = json_decode((string) file_get_contents($file), true);
            $bookIndex = $this->buildBookIndex();
            $digits    = is_array($bible['digit'] ?? null) && count($bible['digit']) >= 10
                ? $bible['digit']
                : null;

            $translation = match ($language) {
                'my'    => 'Judson 1835',
                'td'    => 'Lai Siangtho 1932',
                default => 'BSB',
            };

            $cards = [];
            foreach ($refs as $ref) {
                [$bookNum, $chapter, $verseNum] = $this->parseRef($ref, $bookIndex);
                if ($bookNum === null) {
                    continue;
                }

                $text = trim((string) ($bible['book'][$bookNum]['chapter'][$chapter]['verse'][$verseNum]['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 420) {
                    continue;
                }

                $nativeBook = $bible['book'][$bookNum]['info']['name'] ?? null;
                $displayRef = $nativeBook !== null
                    ? $nativeBook . ' ' . $this->localizeNum($chapter, $digits) . ':' . $this->localizeNum($verseNum, $digits)
                    : $ref;

                $cards[] = [
                    'type'   => 'verse',
                    'text'   => $text,
                    'source' => $displayRef . ' (' . $translation . ')',
                ];
            }

            return $cards;
        });
    }

    /** Pick verse references that match keywords in $mood; fall back to the default set. */
    private function verseRefsForMood(string $mood): array
    {
        $lower   = mb_strtolower($mood);
        $matched = [];

        foreach (self::MOOD_VERSES as $keyword => $refs) {
            if (str_contains($lower, $keyword)) {
                foreach ($refs as $ref) {
                    $matched[$ref] = true;
                }
            }
        }

        return array_keys($matched) ?: self::DEFAULT_VERSES;
    }

    /**
     * Parse an English reference like "1 Thessalonians 5:18" or "Psalm 23:1".
     * Returns [bookNumber, chapter, verse] or [null, null, null] on failure.
     */
    private function parseRef(string $ref, array $bookIndex): array
    {
        $none = [null, null, null];

        if (! preg_match(
            '/^((?:[123]\s+)?[a-z][a-z ]*?)\s+(\d+):(\d+)\s*$/iu',
            trim($ref),
            $m,
        )) {
            return $none;
        }

        $bookNum = $bookIndex[strtolower(trim($m[1]))] ?? null;

        return $bookNum !== null ? [$bookNum, $m[2], $m[3]] : $none;
    }

    /** Build a lowercase English-name → book-number index from the bundled books_en.json. */
    private function buildBookIndex(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $file = base_path('../workers/data/books_en.json');
        if (! is_readable($file)) {
            return $cached = [];
        }

        $books = json_decode((string) file_get_contents($file), true);
        $index = [];

        foreach ((array) $books as $num => $info) {
            $names = array_filter(array_merge(
                [$info['name'] ?? '', $info['shortname'] ?? ''],
                $info['abbr'] ?? [],
            ));
            foreach ($names as $name) {
                $key         = strtolower((string) $name);
                $index[$key] = (string) $num;
                if (! str_ends_with($key, 's')) {
                    $index[$key . 's'] = (string) $num;
                }
            }
        }

        return $cached = $index;
    }

    /** Replace ASCII digits with the Bible's native digit glyphs (Burmese etc.). */
    private function localizeNum(string $number, ?array $digits): string
    {
        if ($digits === null) {
            return $number;
        }

        return preg_replace_callback('/\d/', fn ($m) => (string) ($digits[(int) $m[0]] ?? $m[0]), $number) ?? $number;
    }
}
