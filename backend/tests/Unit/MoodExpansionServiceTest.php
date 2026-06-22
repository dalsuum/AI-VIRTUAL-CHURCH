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
}
