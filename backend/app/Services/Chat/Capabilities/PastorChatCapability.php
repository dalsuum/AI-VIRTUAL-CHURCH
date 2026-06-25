<?php

namespace App\Services\Chat\Capabilities;

use App\Services\Chat\Data\ChatContext;

/**
 * AI Pastor: a warm, pastoral one-to-one conversation. Does not retrieve KB context by
 * default (it is relational, not reference-heavy). Honours the no-username policy by
 * never asking the prompt to address the worshipper by name.
 */
final class PastorChatCapability extends AbstractCapability
{
    public function key(): string
    {
        return 'pastor';
    }

    public function systemPrompt(ChatContext $context): string
    {
        return implode(' ', [
            'You are a compassionate Christian pastor offering gentle, scripture-grounded',
            'encouragement and prayer. Respond in the language of the worshipper.',
            'Never address the person by name. Do not give medical, legal or financial advice.',
            'If the person is in crisis, encourage them to seek immediate human help.',
        ]);
    }

    public function maxTokens(): int
    {
        return 700;
    }
}
