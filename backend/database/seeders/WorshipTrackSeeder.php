<?php

namespace Database\Seeders;

use App\Models\WorshipTrack;
use Illuminate\Database\Seeder;

/**
 * Demo catalog for the AI Worship Radio. METADATA ONLY — no audio is hosted.
 * The YouTube links point at well-known public worship uploads and are
 * illustrative starting points; admins should verify/replace them from the
 * Music tab. `themes`/`moods` deliberately line up with MoodExpansionService
 * so a freshly seeded install returns sensible recommendations immediately.
 *
 * Run: php artisan db:seed --class=Database\\Seeders\\WorshipTrackSeeder
 */
class WorshipTrackSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->tracks() as $t) {
            WorshipTrack::updateOrCreate(
                ['title' => $t['title'], 'language' => $t['language']],
                $t,
            );
        }
    }

    private function tracks(): array
    {
        // Links are NOT hand-seeded (guessed ids break with "Video unavailable").
        // Populate real, embeddable, content-filtered links with:
        //   php artisan worship:backfill-links --all
        return [
            // ── English ──────────────────────────────────────────────────────
            [
                'title' => 'It Is Well', 'artist' => 'Bethel Music', 'language' => 'en',
                'genre' => 'worship', 'duration' => 600,
                'themes' => ['peace', 'trust', 'comfort', 'faith'],
                'moods' => ['anxiety', 'peace', 'sad'],
                'scriptures' => ['Isaiah 26:3'],
                'popularity' => 90, 'lyrics_available' => true,
            ],
            [
                'title' => 'Way Maker', 'artist' => 'Sinach', 'language' => 'en',
                'genre' => 'worship', 'duration' => 420,
                'themes' => ['faith', 'hope', 'promise', 'presence'],
                'moods' => ['hopeful', 'seeking', 'need prayer'],
                'scriptures' => ['Isaiah 43:19'],
                'popularity' => 95, 'lyrics_available' => true,
            ],
            [
                'title' => 'Goodness of God', 'artist' => 'CeCe Winans', 'language' => 'en',
                'genre' => 'worship', 'duration' => 300,
                'themes' => ['gratitude', 'thanksgiving', 'faithfulness', 'praise'],
                'moods' => ['thankful', 'grateful', 'happy'],
                'scriptures' => ['Psalm 23:6'],
                'popularity' => 88, 'lyrics_available' => true,
            ],
            [
                'title' => 'Reckless Love', 'artist' => 'Cory Asbury', 'language' => 'en',
                'genre' => 'worship', 'duration' => 340,
                'themes' => ['love', 'identity', 'grace', 'restoration'],
                'moods' => ['depression', 'broken heart', 'lonely'],
                'scriptures' => ['Luke 15:4'],
                'popularity' => 80, 'lyrics_available' => true,
            ],
            [
                'title' => 'Peace Be Still', 'artist' => 'Hope Darst', 'language' => 'en',
                'genre' => 'worship', 'duration' => 360,
                'themes' => ['peace', 'rest', 'trust', 'fear'],
                'moods' => ['anxiety', 'tired', 'peace'],
                'scriptures' => ['Mark 4:39'],
                'popularity' => 70, 'lyrics_available' => true,
            ],
            [
                'title' => 'Raise a Hallelujah', 'artist' => 'Bethel Music', 'language' => 'en',
                'genre' => 'worship', 'duration' => 380,
                'themes' => ['praise', 'faith', 'fire', 'surrender'],
                'moods' => ['revival', 'happy', 'hopeful'],
                'scriptures' => ['2 Chronicles 20:22'],
                'popularity' => 82, 'lyrics_available' => true,
            ],
            [
                'title' => 'Great Are You Lord', 'artist' => 'All Sons & Daughters', 'language' => 'en',
                'genre' => 'worship', 'duration' => 320,
                'themes' => ['praise', 'gratitude', 'breath', 'thanksgiving'],
                'moods' => ['thankful', 'grateful', 'joyful'],
                'scriptures' => ['Psalm 145:3'],
                'popularity' => 75, 'lyrics_available' => true,
            ],
            [
                'title' => 'Lord I Need You', 'artist' => 'Matt Maher', 'language' => 'en',
                'genre' => 'worship', 'duration' => 290,
                'themes' => ['forgiveness', 'grace', 'mercy', 'surrender'],
                'moods' => ['repentance', 'need prayer', 'seeking'],
                'scriptures' => ['Psalm 51:10'],
                'popularity' => 72, 'lyrics_available' => true,
            ],

            // ── Burmese (my) ─────────────────────────────────────────────────
            [
                'title' => 'ကိုယ်တော်ကြောင့်', 'artist' => 'Saw Eh Htoo', 'language' => 'my',
                'genre' => 'worship', 'duration' => 360,
                'themes' => ['gratitude', 'praise', 'thanksgiving'],
                'moods' => ['thankful', 'grateful', 'happy'],
                'scriptures' => ['Psalm 100:4'], 'popularity' => 60, 'lyrics_available' => true,
            ],
            [
                'title' => 'ငြိမ်သက်ခြင်း', 'artist' => 'Esther Hla Sein', 'language' => 'my',
                'genre' => 'worship', 'duration' => 330,
                'themes' => ['peace', 'rest', 'trust', 'comfort'],
                'moods' => ['anxiety', 'peace', 'tired'],
                'scriptures' => ['John 14:27'], 'popularity' => 58, 'lyrics_available' => true,
            ],
            [
                'title' => 'မျှော်လင့်ခြင်း', 'artist' => 'Naw Paw', 'language' => 'my',
                'genre' => 'worship', 'duration' => 340,
                'themes' => ['hope', 'faith', 'healing', 'restoration'],
                'moods' => ['depression', 'sad', 'hopeful'],
                'scriptures' => ['Jeremiah 29:11'], 'popularity' => 55, 'lyrics_available' => true,
            ],
            [
                'title' => 'ချစ်ခြင်းမေတ္တာ', 'artist' => 'David Lazum', 'language' => 'my',
                'genre' => 'worship', 'duration' => 350,
                'themes' => ['love', 'presence', 'companionship', 'jesus'],
                'moods' => ['lonely', 'broken heart', 'seeking'],
                'scriptures' => ['Romans 8:38'], 'popularity' => 52, 'lyrics_available' => true,
            ],
            [
                'title' => 'ဘုရားသခင်ကို ချီးမွမ်းပါ', 'artist' => 'Yangon Praise', 'language' => 'my',
                'genre' => 'praise', 'duration' => 300,
                'themes' => ['praise', 'celebration', 'joy', 'fire'],
                'moods' => ['revival', 'happy', 'joyful'],
                'scriptures' => ['Psalm 150:6'], 'popularity' => 50, 'lyrics_available' => true,
            ],

            // ── Zolai / Tedim (td) ───────────────────────────────────────────
            [
                'title' => 'Pasian Tungah Lungdam', 'artist' => 'Cing Sian Mang', 'language' => 'td',
                'genre' => 'worship', 'duration' => 340,
                'themes' => ['gratitude', 'thanksgiving', 'praise'],
                'moods' => ['thankful', 'grateful', 'happy'],
                'scriptures' => ['Psalm 95:2'], 'popularity' => 48, 'lyrics_available' => true,
            ],
            [
                'title' => 'Thinnuamna', 'artist' => 'Niang Khan Cing', 'language' => 'td',
                'genre' => 'worship', 'duration' => 320,
                'themes' => ['peace', 'rest', 'trust', 'comfort'],
                'moods' => ['anxiety', 'peace', 'tired'],
                'scriptures' => ['Philippians 4:7'], 'popularity' => 46, 'lyrics_available' => true,
            ],
            [
                'title' => 'Lametna', 'artist' => 'Thang Khan Pau', 'language' => 'td',
                'genre' => 'worship', 'duration' => 360,
                'themes' => ['hope', 'faith', 'healing', 'restoration'],
                'moods' => ['depression', 'sad', 'hopeful'],
                'scriptures' => ['Romans 15:13'], 'popularity' => 44, 'lyrics_available' => true,
            ],
            [
                'title' => 'Itna Lianpi', 'artist' => 'Dim Khan Khai', 'language' => 'td',
                'genre' => 'worship', 'duration' => 350,
                'themes' => ['love', 'presence', 'companionship', 'jesus'],
                'moods' => ['lonely', 'broken heart', 'seeking'],
                'scriptures' => ['John 15:13'], 'popularity' => 42, 'lyrics_available' => true,
            ],
            [
                'title' => 'Pasian Phatna La', 'artist' => 'Tedim Praise', 'language' => 'td',
                'genre' => 'praise', 'duration' => 300,
                'themes' => ['praise', 'celebration', 'joy', 'fire'],
                'moods' => ['revival', 'happy', 'joyful'],
                'scriptures' => ['Psalm 47:1'], 'popularity' => 40, 'lyrics_available' => true,
            ],
        ];
    }
}
