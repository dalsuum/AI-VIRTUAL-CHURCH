<?php

namespace App\Services\Chat\Contracts;

use App\Services\Chat\Data\ChatContext;

/**
 * Strategy for one product surface (Pastor Chat, Bible Study, Prayer, Counseling,
 * Worship, …). The orchestrator owns the FIXED 13-step pipeline; a capability supplies
 * the VARIABLE parts — persona/system instructions, whether to use the Knowledge Base
 * and with what query, the token budget, and an optional model preference.
 *
 * Adding a new product = adding one class implementing this interface and registering it
 * in CapabilityResolver. No orchestrator change. (Open/Closed Principle.)
 */
interface ChatCapability
{
    /** Stable key, matched against ChatSession::session_type (e.g. 'pastor', 'study'). */
    public function key(): string;

    /** System-prompt persona/instructions for this surface, in the detected language. */
    public function systemPrompt(ChatContext $context): string;

    /** Whether this surface should retrieve Knowledge Base context for the turn. */
    public function usesKnowledge(): bool;

    /** The retrieval query for the KB layer, or null to skip. Defaults to the user text. */
    public function knowledgeQuery(ChatContext $context): ?string;

    /** Max completion tokens for this surface. */
    public function maxTokens(): int;

    /** Optional explicit model id; null lets the inference layer route by language. */
    public function modelPreference(ChatContext $context): ?string;
}
