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

    public function test_mood_keys_are_the_six_universal_ids(): void
    {
        $this->assertSame(
            ['energy', 'feel_good', 'focus', 'love', 'relax', 'heartbreak'],
            $this->svc()->moodKeys(),
        );
    }

    public function test_chip_id_expands_to_its_concepts(): void
    {
        $tags = $this->svc()->expand('relax');

        $this->assertContains('peace', $tags);
        $this->assertContains('rest', $tags);
        $this->assertContains('relax', $tags, 'the mood id itself is always included');
    }

    public function test_free_text_maps_to_the_right_category(): void
    {
        $svc = $this->svc();

        $this->assertContains('peace', $svc->expand("I'm anxious"), 'anxious → relax');
        $this->assertContains('loneliness', $svc->expand('I feel lonely'), 'lonely → heartbreak');
        $this->assertContains('strength', $svc->expand('I need encouragement'), 'encouragement → energy');
        $this->assertContains('wisdom', $svc->expand("I'm studying"), 'studying → focus');
    }

    public function test_free_text_can_touch_multiple_categories(): void
    {
        // "lonely" (heartbreak) + "tired" (relax) both contribute.
        $tags = $this->svc()->expand('I feel so lonely and tired tonight');

        $this->assertContains('loneliness', $tags);
        $this->assertContains('rest', $tags);
    }

    public function test_legacy_stored_mood_keys_still_expand(): void
    {
        // Sessions saved before the redesign stored keys like "peace"/"anxiety";
        // these resolve through the trigger words so resumed sessions keep working.
        $this->assertContains('peace', $this->svc()->expand('peace'));
        $this->assertContains('peace', $this->svc()->expand('anxiety'));
        $this->assertContains('joy', $this->svc()->expand('happy'));
    }

    public function test_unknown_mood_still_returns_its_own_token(): void
    {
        $this->assertSame(['flibbertigibbet'], $this->svc()->expand('flibbertigibbet'));
    }

    public function test_blank_mood_returns_empty(): void
    {
        $this->assertSame([], $this->svc()->expand('   '));
    }

    public function test_label_localizes_chip_text_per_language(): void
    {
        $svc = $this->svc();

        $this->assertSame('Relax', $svc->label('relax', 'en'));
        $this->assertSame('ငြိမ်သက်', $svc->label('relax', 'my'));
        $this->assertSame('Lungmuanna', $svc->label('relax', 'td'));
    }

    public function test_labels_returns_all_language_variants(): void
    {
        $labels = $this->svc()->labels('feel_good');

        $this->assertSame('Feel Good', $labels['en']);
        $this->assertSame('ပျော်ရွှင်', $labels['my']);
        $this->assertSame('Lungdam', $labels['td']);
    }

    public function test_emoji_comes_from_config(): void
    {
        $this->assertSame('🌿', $this->svc()->emoji('relax'));
        $this->assertSame('🎵', $this->svc()->emoji('not_a_mood'));
    }

    public function test_unmapped_free_text_falls_back_to_titlecased_text(): void
    {
        $this->assertSame('I Feel Lost', $this->svc()->label('i feel lost', 'td'));
    }
}
