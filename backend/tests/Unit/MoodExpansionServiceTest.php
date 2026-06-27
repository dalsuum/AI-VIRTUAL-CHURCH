<?php

namespace Tests\Unit;

use App\Services\MoodExpansionService;
use Tests\TestCase;

class MoodExpansionServiceTest extends TestCase
{
    private function svc(): MoodExpansionService
    {
        return new MoodExpansionService();
    }

    public function test_chip_label_expands_to_dictionary_themes(): void
    {
        $tags = $this->svc()->expand('Anxiety');

        $this->assertContains('peace', $tags);
        $this->assertContains('trust', $tags);
        $this->assertContains('anxiety', $tags, 'the mood itself is always included');
    }

    public function test_free_text_matches_dictionary_keywords(): void
    {
        $tags = $this->svc()->expand('I feel so lonely and tired tonight');

        // Both "lonely" and "tired" keys contribute their tags.
        $this->assertContains('presence', $tags);
        $this->assertContains('rest', $tags);
    }

    public function test_unknown_mood_still_returns_its_own_token(): void
    {
        $tags = $this->svc()->expand('flibbertigibbet');

        $this->assertSame(['flibbertigibbet'], $tags);
    }

    public function test_blank_mood_returns_empty(): void
    {
        $this->assertSame([], $this->svc()->expand('   '));
    }

    public function test_label_localizes_chip_text_per_language(): void
    {
        $svc = $this->svc();

        $this->assertSame('Anxiety', $svc->label('anxiety', 'en'));
        $this->assertSame('စိုးရိမ်ပူပန်', $svc->label('anxiety', 'my'));
        $this->assertSame('Mangbatna', $svc->label('anxiety', 'td'));
    }

    public function test_labels_returns_all_language_variants(): void
    {
        $labels = $this->svc()->labels('peace');

        $this->assertSame('Peace', $labels['en']);
        $this->assertSame('ငြိမ်သက်', $labels['my']);
        $this->assertSame('Lungmuanna', $labels['td']);
    }

    public function test_unmapped_mood_falls_back_to_titlecased_english(): void
    {
        // Free text in any language passes through (no native term to swap in).
        $this->assertSame('I Feel Lost', $this->svc()->label('i feel lost', 'td'));
    }
}
