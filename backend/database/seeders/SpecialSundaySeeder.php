<?php

namespace Database\Seeders;

use App\Models\SpecialSunday;
use Illuminate\Database\Seeder;
use Normalizer;

/**
 * Upserts the special-Sundays catalog from config/special_sundays.php by `key`,
 * so editing that file and re-running the seeder is all it takes to add/adjust
 * observances (per region) without touching code or migrations.
 *
 *   php artisan db:seed --class=Database\\Seeders\\SpecialSundaySeeder
 *
 * my/td title + brief text is normalized to Unicode NFC here (the Myanmar
 * Unicode invariant the rest of the stack — Zawgyi guard included — assumes).
 */
class SpecialSundaySeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('special_sundays.observances', []) as $entry) {
            if (empty($entry['key'])) {
                continue;
            }

            SpecialSunday::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'rule_type'   => $entry['rule_type'],
                    'rule'        => $entry['rule'] ?? [],
                    'titles'      => $this->normalizeLangMap($entry['titles'] ?? []),
                    'briefs'      => $this->normalizeLangMap($entry['briefs'] ?? []),
                    'sermon_tags' => array_values($entry['sermon_tags'] ?? []),
                    'music_moods' => array_values($entry['music_moods'] ?? []),
                    'region'      => $entry['region'] ?? null,
                    'priority'    => $entry['priority'] ?? 50,
                    'active'      => $entry['active'] ?? true,
                ],
            );
        }
    }

    /** NFC-normalize each localized string so Myanmar and CJK text stay canonical Unicode. */
    private function normalizeLangMap(array $map): array
    {
        foreach ($map as $lang => $text) {
            if (is_string($text) && class_exists(Normalizer::class)) {
                $map[$lang] = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
            }
        }

        return $map;
    }
}
