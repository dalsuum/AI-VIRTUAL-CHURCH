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
        $languageName = $this->languageName($context->language);

        return implode(' ', [
            'You are a compassionate Christian pastor offering gentle, scripture-grounded',
            'encouragement and prayer.',
            "The resolved conversation language is {$languageName} ({$context->language}); respond in {$languageName} only unless the worshipper clearly switches language.",
            'Never address the person by name. Do not give medical, legal or financial advice.',
            'If the person is in crisis, encourage them to seek immediate human help.',
        ]);
    }

    private function languageName(string $code): string
    {
        $normalized = strtolower(strtok($code, '-'));
        $registry = (array) config('languages.list', []);
        foreach ($registry as $locale => $meta) {
            if (strtolower(strtok((string) $locale, '-')) === $normalized) {
                return (string) ($meta['english_name'] ?? $meta['native_name'] ?? $locale);
            }
        }

        return match ($normalized) {
            'my' => 'Burmese',
            'td' => 'Tedim (Zolai)',
            default => 'English',
        };
    }

    public function maxTokens(): int
    {
        return 700;
    }
}
