<?php

namespace Tests\Feature;

use App\Services\Chat\Support\HeuristicLanguageDetector;
use App\Services\MoodExpansionService;
use Tests\TestCase;

/**
 * Milestone 6 — language intelligence and vocabulary regression.
 *
 * Guards the vocabulary/prompt improvements made in this milestone against
 * silent breakage: worship-radio synonym routing, mood-trigger integrity (no
 * duplicates), enriched Pastor-Chat language detection, and locale-key fallback
 * safety. These are data/config assertions only — no architecture, API, or
 * schema is exercised.
 */
class MultilingualMilestoneSixTest extends TestCase
{
    private function locales(): array
    {
        $dir = base_path('../frontend/src/i18n/locales');
        $out = [];
        foreach (glob($dir . '/*.json') as $file) {
            $code = basename($file, '.json');
            $out[$code] = json_decode((string) file_get_contents($file), true);
        }

        return $out;
    }

    /** Flatten a nested locale array into dot-path leaf keys. */
    private function leafKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $k => $v) {
            $path = $prefix === '' ? (string) $k : "$prefix.$k";
            if (is_array($v)) {
                $keys = array_merge($keys, $this->leafKeys($v, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    public function test_new_worship_radio_synonyms_route_to_the_right_mood(): void
    {
        $svc = app(MoodExpansionService::class);

        // Each newly added free-text synonym must expand to its mood's concepts.
        $this->assertContains('victory', $svc->expand('mighty'));      // energy
        $this->assertContains('joy', $svc->expand('rejoice'));         // feel_good
        $this->assertContains('meditation', $svc->expand('ponder'));   // focus
        $this->assertContains('family', $svc->expand('beloved'));      // love
        $this->assertContains('peace', $svc->expand('serene'));        // relax
        $this->assertContains('grief', $svc->expand('heartache'));     // heartbreak
    }

    public function test_mood_triggers_have_no_duplicates_within_a_mood(): void
    {
        $moods = config('worship_moods.moods');
        $this->assertNotEmpty($moods);

        foreach ($moods as $id => $cfg) {
            $triggers = array_map(
                fn ($t) => mb_strtolower(trim((string) $t)),
                (array) ($cfg['triggers'] ?? []),
            );
            $this->assertSame(
                array_values(array_unique($triggers)),
                array_values($triggers),
                "Mood '$id' has duplicate trigger words",
            );
        }
    }

    public function test_pastor_chat_detects_languages_from_native_christian_phrasing(): void
    {
        $detector = new HeuristicLanguageDetector(['en', 'fr', 'de', 'es']);

        // Phrases built only from the Milestone-6 enriched native signal words.
        $this->assertSame('fr', $detector->detect('Sauveur, ton Esprit, ta louange et ta croix'));
        $this->assertSame('de', $detector->detect('Gott, deine Vergebung, deine Hoffnung und dein Lobpreis'));
        $this->assertSame('es', $detector->detect('Señor, tu perdón, tu esperanza y tu alabanza'));
    }

    public function test_every_locale_key_exists_in_english_so_fallback_always_resolves(): void
    {
        $locales = $this->locales();
        $this->assertArrayHasKey('en', $locales);
        $enKeys = array_flip($this->leafKeys($locales['en']));

        foreach ($locales as $code => $data) {
            if ($code === 'en' || !is_array($data)) {
                continue;
            }
            foreach ($this->leafKeys($data) as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $enKeys,
                    "Locale '$code' key '$key' has no English fallback",
                );
            }
        }
    }

    public function test_all_registry_languages_are_enabled_and_unicode_safe(): void
    {
        foreach (config('languages.list') as $code => $meta) {
            $this->assertTrue($meta['enabled'], "Language '$code' should be enabled");
            $this->assertNotSame('', trim((string) $meta['native_name']));
            // Native endonyms must be valid UTF-8 (no mojibake in the registry).
            $this->assertSame(
                $meta['native_name'],
                mb_convert_encoding($meta['native_name'], 'UTF-8', 'UTF-8'),
                "Language '$code' native_name is not valid UTF-8",
            );
        }
    }
}
