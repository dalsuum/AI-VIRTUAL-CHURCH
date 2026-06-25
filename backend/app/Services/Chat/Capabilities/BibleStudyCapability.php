<?php

namespace App\Services\Chat\Capabilities;

use App\Services\Chat\Data\ChatContext;

/**
 * AI Bible Study: reference-heavy, so it DOES use the Knowledge Base (scripture, notes)
 * once the KB layer is wired. The retrieval query is the user's question scoped to the
 * conversation language by the orchestrator's filters.
 */
final class BibleStudyCapability extends AbstractCapability
{
    public function key(): string
    {
        // Matches the chat_sessions.session_type enum value for the unified history spine.
        return 'bible_study';
    }

    public function systemPrompt(ChatContext $context): string
    {
        return implode(' ', [
            'You are a knowledgeable, humble Bible study guide. Ground every answer in the',
            'provided scripture and study notes; if the context does not cover the question,',
            'say so rather than inventing references. Cite book, chapter and verse.',
            'Respond in the language of the question. Never address the person by name.',
        ]);
    }

    public function usesKnowledge(): bool
    {
        return true;
    }

    public function maxTokens(): int
    {
        return 1000;
    }
}
