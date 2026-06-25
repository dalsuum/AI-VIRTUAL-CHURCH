<?php

namespace Database\Seeders;

use App\Models\Vocabulary;
use Illuminate\Database\Seeder;

/**
 * Seeds the vocabulary reference from the original hand-curated JSON
 * (frontend/src/data/zolai_vocabulary.json). Upserts by zolai+english so
 * re-running is idempotent and never duplicates a row. After seeding, the DB is
 * the source of truth — admins edit via the Vocabulary tab, not the JSON.
 *
 *   php artisan db:seed --class=Database\\Seeders\\VocabularySeeder
 */
class VocabularySeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('../frontend/src/data/zolai_vocabulary.json');
        if (! is_file($path)) {
            $this->command?->warn("VocabularySeeder: source file not found at {$path}; skipping.");
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->command?->warn('VocabularySeeder: could not parse source JSON; skipping.');
            return;
        }

        foreach ($rows as $row) {
            $zolai   = trim((string) ($row['zolai'] ?? ''));
            $english = trim((string) ($row['english'] ?? ''));
            if ($zolai === '' || $english === '') {
                continue;
            }

            $category = isset($row['category']) ? trim((string) $row['category']) : null;

            // Match on zolai+english+category: a few words (e.g. "siangtho") appear
            // legitimately under more than one category, so category is part of identity.
            $attrs = [
                'burmese' => isset($row['burmese']) ? trim((string) $row['burmese']) : null,
                'hebrew'  => isset($row['hebrew']) && $row['hebrew'] !== '' ? trim((string) $row['hebrew']) : null,
                'notes'   => isset($row['notes']) && $row['notes'] !== '' ? trim((string) $row['notes']) : null,
                'source'  => 'reference',
            ];

            // Optional per-language glosses (falam, hakha, matu, mizo, paite, sizang).
            // Only overwrite when the JSON actually carries a value, so admin edits to
            // a blank language cell survive a reseed.
            foreach (['falam', 'hakha', 'matu', 'mizo', 'paite', 'sizang'] as $lang) {
                if (isset($row[$lang]) && trim((string) $row[$lang]) !== '') {
                    $attrs[$lang] = trim((string) $row[$lang]);
                }
            }

            Vocabulary::updateOrCreate(
                ['zolai' => $zolai, 'english' => $english, 'category' => $category],
                $attrs,
            );
        }
    }
}
