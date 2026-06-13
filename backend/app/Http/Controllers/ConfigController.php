<?php

namespace App\Http\Controllers;

use App\Models\ServiceIntake;
use App\Models\Setting;
use App\Models\Testimony;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Public, unauthenticated app configuration consumed by the intake form before a
 * worshipper has a session: which moods to offer, which music sources are enabled,
 * and whether scheduling is available. Admins shape these via the admin settings.
 */
class ConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $language = in_array($request->query('language'), ['en', 'my', 'td'], true)
            ? $request->query('language')
            : 'en';
        $mood = trim((string) $request->query('mood', ''));
        $mood = mb_substr($mood, 0, 100);

        return response()->json([
            'moods'              => Setting::moods(),
            'music_sources'      => Setting::enabledMusicSources(),
            'scheduling_enabled' => Setting::schedulingEnabled(),
            'enabled_languages'  => Setting::enabledLanguages(),
            'countdown_cards'    => $this->countdownCards($mood, $language),
        ]);
    }

    /** Cards shown during the preparation countdown. Public endpoint, text only. */
    private function countdownCards(string $mood = '', string $language = 'en'): array
    {
        if (Setting::get('countdown_content_enabled', '1') !== '1') {
            return [];
        }

        $source = Setting::get('countdown_content_source', 'both');
        if (! in_array($source, ['banners', 'testimonies', 'online', 'both', 'all'], true)) {
            return [];
        }

        $cards = [];

        if (in_array($source, ['banners', 'both', 'all'], true)) {
            foreach (Setting::countdownBanners() as $banner) {
                $cards[] = [
                    'type' => 'banner',
                    'text' => $banner['text'],
                    'source' => $banner['source'],
                ];
            }
        }

        if (in_array($source, ['online', 'all'], true)) {
            $online = $this->bibleVerseCard($language);
            if ($online) {
                $cards[] = $online;
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

    /** Bible verse card in the service language. English can use the online source; local data is fallback and non-English source. */
    private function bibleVerseCard(string $language): ?array
    {
        if ($language === 'en') {
            return $this->onlineVerseCard() ?: $this->localBibleVerseCard('en');
        }

        return $this->localBibleVerseCard($language);
    }


    /** Fetch one English online Bible verse from a fixed allowlisted provider, with cache. */
    private function onlineVerseCard(): ?array
    {
        try {
            return Cache::remember('countdown_online_verse_web_nt', now()->addHours(6), function () {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get('https://bible-api.com/data/web/random/NT');

                if (! $response->ok()) {
                    return null;
                }

                $data = $response->json();
                $verse = is_array($data) ? ($data['random_verse'] ?? $data) : null;
                if (! is_array($verse)) {
                    return null;
                }

                $text = trim((string) ($verse['text'] ?? ''));
                if ($text === '') {
                    return null;
                }

                $book = trim((string) ($verse['book'] ?? $verse['book_name'] ?? ''));
                $chapter = trim((string) ($verse['chapter'] ?? ''));
                $verseNo = trim((string) ($verse['verse'] ?? ''));
                $reference = trim($book . ' ' . $chapter . ($verseNo !== '' ? ':' . $verseNo : ''));

                return [
                    'type' => 'online',
                    'text' => mb_substr($text, 0, 420),
                    'source' => $reference !== '' ? $reference . ' (WEB)' : 'World English Bible',
                ];
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /** Random NT verse from bundled Bible JSON for English/Burmese/Tedim. */
    private function localBibleVerseCard(string $language): ?array
    {
        $language = in_array($language, ['en', 'my', 'td'], true) ? $language : 'en';
        $cacheKey = 'countdown_local_bible_verse_' . $language;

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($language) {
            $file = match ($language) {
                'my' => base_path('../workers/data/judson1835.json'),
                'td' => base_path('../workers/data/tedim1932.json'),
                default => base_path('../workers/data/bsb.json'),
            };

            if (! is_readable($file)) {
                return null;
            }

            $bible = json_decode((string) file_get_contents($file), true);
            $books = is_array($bible) ? ($bible['book'] ?? []) : [];
            if (! is_array($books) || $books === []) {
                return null;
            }

            $bookNumbers = array_values(array_filter(array_map('strval', array_keys($books)), fn ($n) => (int) $n >= 40 && (int) $n <= 66));
            if ($bookNumbers === []) {
                return null;
            }

            for ($i = 0; $i < 50; $i++) {
                $bookNum = $bookNumbers[array_rand($bookNumbers)];
                $book = $books[$bookNum] ?? null;
                $chapters = $book['chapter'] ?? [];
                if (! is_array($chapters) || $chapters === []) {
                    continue;
                }
                $chapterNum = (string) array_rand($chapters);
                $verses = $chapters[$chapterNum]['verse'] ?? [];
                if (! is_array($verses) || $verses === []) {
                    continue;
                }
                $verseNum = (string) array_rand($verses);
                $text = trim((string) ($verses[$verseNum]['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 420) {
                    continue;
                }

                $bookName = trim((string) ($book['info']['name'] ?? ''));
                $reference = trim($bookName . ' ' . $this->localizedNumber($chapterNum, $bible) . ':' . $this->localizedNumber($verseNum, $bible));
                $translation = match ($language) {
                    'my' => 'Judson 1835',
                    'td' => 'Lai Siangtho 1932',
                    default => 'BSB',
                };

                return [
                    'type' => 'online',
                    'text' => mb_substr($text, 0, 420),
                    'source' => $reference !== '' ? $reference . ' (' . $translation . ')' : $translation,
                ];
            }

            return null;
        });
    }

    private function localizedNumber(string $number, array $bible): string
    {
        $digits = $bible['digit'] ?? null;
        if (! is_array($digits) || count($digits) < 10) {
            return $number;
        }

        return preg_replace_callback('/\d/', fn ($m) => (string) ($digits[(int) $m[0]] ?? $m[0]), $number) ?? $number;
    }
}
