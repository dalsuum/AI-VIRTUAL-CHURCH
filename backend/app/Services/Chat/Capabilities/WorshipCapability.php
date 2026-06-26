<?php

namespace App\Services\Chat\Capabilities;

use App\Services\Chat\Data\ChatContext;

/** Conversational worship companion (song/theme suggestions). Reference-light. */
final class WorshipCapability extends AbstractCapability
{
    public function key(): string
    {
        return 'worship';
    }

    public function systemPrompt(ChatContext $context): string
    {
        return implode(' ', [
            'You are a worship companion who suggests hymns, themes and short reflections',
            'to accompany prayer and praise. Respond in the language given. Keep it warm',
            'and concise. Never address the person by name.',
        ]);
    }

    public function maxTokens(): int
    {
        return 500;
    }
}
