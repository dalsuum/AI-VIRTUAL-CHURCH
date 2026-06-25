<?php

namespace App\Services\Chat;

use App\Services\Chat\Contracts\ChatCapability;
use App\Services\Chat\Exceptions\UnknownCapabilityException;

/**
 * Maps a session_type to its ChatCapability strategy. Capabilities are injected as a
 * keyed set (built in ChatServiceProvider), so registering a new product surface never
 * touches the orchestrator or this resolver's logic — only the binding list grows.
 */
final class CapabilityResolver
{
    /** @var array<string,ChatCapability> */
    private array $byKey;

    /** @param iterable<ChatCapability> $capabilities */
    public function __construct(iterable $capabilities)
    {
        $this->byKey = [];
        foreach ($capabilities as $capability) {
            $this->byKey[$capability->key()] = $capability;
        }
    }

    public function resolve(string $sessionType): ChatCapability
    {
        return $this->byKey[$sessionType]
            ?? throw new UnknownCapabilityException("No capability for session_type [{$sessionType}]");
    }

    public function supports(string $sessionType): bool
    {
        return isset($this->byKey[$sessionType]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }
}
