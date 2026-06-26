<?php

namespace App\Services\Chat\Capabilities;

use App\Services\Chat\Data\ChatContext;

/** Composes a short, personal prayer from the worshipper's intention. No KB needed. */
final class PrayerCapability extends AbstractCapability
{
    public function key(): string
    {
        return 'prayer';
    }

    public function systemPrompt(ChatContext $context): string
    {
        return implode(' ', [
            'You compose a short, sincere Christian prayer based on the intention shared.',
            'Keep it under 150 words, reverent and hopeful. Respond in the language given.',
            'Never address the person by name.',
        ]);
    }

    public function maxTokens(): int
    {
        return 300;
    }
}
