<?php

namespace App\Services\Chat\Guardrails;

use App\Services\Chat\Guardrails\Contracts\PolicyRepository;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Config-backed PolicyRepository: reads policies from config/guardrails.php under the
 * 'policies' key. The Config repository is injected (no config() helper) so the class is
 * a pure, testable mapping with no static state.
 */
final class ConfigPolicyRepository implements PolicyRepository
{
    public function __construct(private readonly Config $config) {}

    public function get(string $name, array $default = []): array
    {
        return (array) $this->config->get("guardrails.policies.{$name}", $default);
    }
}
