<?php

namespace Database\Seeders;

use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingPlanDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the canonical "Bible in a Year" plan: the 1,189 chapters of the Protestant
 * canon, in order, spread as evenly as possible across 365 days. Plans are DATA —
 * adding NT-in-90, Psalms-in-30, Chronological, Advent/Lent later is just more seed
 * rows, no code.
 *
 * Idempotent: the plan is created once (by slug) and days are only generated when
 * absent, so re-running inserts nothing.
 *
 *   php artisan db:seed --class=Database\\Seeders\\ReadingPlansSeeder
 */
class ReadingPlansSeeder extends Seeder
{
    private const SLUG = 'bible-in-a-year';
    private const DAYS = 365;

    /** Protestant canon: book => chapter count (sums to 1,189). */
    private const CANON = [
        'Genesis' => 50, 'Exodus' => 40, 'Leviticus' => 27, 'Numbers' => 36, 'Deuteronomy' => 34,
        'Joshua' => 24, 'Judges' => 21, 'Ruth' => 4, '1 Samuel' => 31, '2 Samuel' => 24,
        '1 Kings' => 22, '2 Kings' => 25, '1 Chronicles' => 29, '2 Chronicles' => 36, 'Ezra' => 10,
        'Nehemiah' => 13, 'Esther' => 10, 'Job' => 42, 'Psalms' => 150, 'Proverbs' => 31,
        'Ecclesiastes' => 12, 'Song of Solomon' => 8, 'Isaiah' => 66, 'Jeremiah' => 52,
        'Lamentations' => 5, 'Ezekiel' => 48, 'Daniel' => 12, 'Hosea' => 14, 'Joel' => 3, 'Amos' => 9,
        'Obadiah' => 1, 'Jonah' => 4, 'Micah' => 7, 'Nahum' => 3, 'Habakkuk' => 3, 'Zephaniah' => 3,
        'Haggai' => 2, 'Zechariah' => 14, 'Malachi' => 4, 'Matthew' => 28, 'Mark' => 16, 'Luke' => 24,
        'John' => 21, 'Acts' => 28, 'Romans' => 16, '1 Corinthians' => 16, '2 Corinthians' => 13,
        'Galatians' => 6, 'Ephesians' => 6, 'Philippians' => 4, 'Colossians' => 4,
        '1 Thessalonians' => 5, '2 Thessalonians' => 3, '1 Timothy' => 6, '2 Timothy' => 4,
        'Titus' => 3, 'Philemon' => 1, 'Hebrews' => 13, 'James' => 5, '1 Peter' => 5, '2 Peter' => 3,
        '1 John' => 5, '2 John' => 1, '3 John' => 1, 'Jude' => 1, 'Revelation' => 22,
    ];

    public function run(): void
    {
        $plan = ReadingPlan::firstOrCreate(
            ['slug' => self::SLUG],
            ['title' => 'Bible in a Year', 'description' => 'Read through the whole Bible in 365 days.', 'day_count' => self::DAYS],
        );

        if ($plan->days()->count() >= self::DAYS) {
            return; // already seeded — idempotent
        }

        DB::transaction(function () use ($plan) {
            $chapters  = $this->flattenCanon();          // 1,189 [book, chapter] pairs, in order
            $remaining = count($chapters);
            $cursor    = 0;

            for ($seq = 1; $seq <= self::DAYS; $seq++) {
                $daysLeft = self::DAYS - $seq + 1;
                $take     = (int) ceil($remaining / $daysLeft);   // even spread
                $passages = array_slice($chapters, $cursor, $take);

                ReadingPlanDay::create([
                    'reading_plan_id' => $plan->id,
                    'sequence'        => $seq,
                    'slug'            => sprintf('day-%03d', $seq),
                    'title'           => 'Day '.$seq,
                    'passages'        => array_values($passages),
                ]);

                $cursor    += $take;
                $remaining -= $take;
            }
        });

        $plan->update(['day_count' => self::DAYS]);
    }

    /** @return array<int, array{book:string, chapter:int}> */
    private function flattenCanon(): array
    {
        $pairs = [];
        foreach (self::CANON as $book => $count) {
            for ($c = 1; $c <= $count; $c++) {
                $pairs[] = ['book' => $book, 'chapter' => $c];
            }
        }

        return $pairs;
    }
}
