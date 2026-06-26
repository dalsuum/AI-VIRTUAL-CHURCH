<?php

namespace App\Services\Chat\Capabilities;

use App\Services\Chat\Contracts\ChatCapability;
use App\Services\Chat\Data\ChatContext;

/**
 * Shared defaults for capabilities so each concrete surface only declares what differs.
 * Keeps the per-product classes tiny while honouring DRY. Subclasses MUST provide a key
 * and a persona; everything else has a sensible override point.
 */
abstract class AbstractCapability implements ChatCapability
{
    public function usesKnowledge(): bool
    {
        return false;
    }

    public function knowledgeQuery(ChatContext $context): ?string
    {
        return $this->usesKnowledge() ? $context->request->message : null;
    }

    public function maxTokens(): int
    {
        return 800;
    }

    public function modelPreference(ChatContext $context): ?string
    {
        return null; // let the inference layer route by language
    }
}
