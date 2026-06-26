<?php

namespace App\Services\Chat\Guardrails;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Resolves the ORDERED, ENABLED list of guards for a stage ('input'|'output') and a
 * capability key, from config/guardrails.php:
 *
 *   guardrails.input.order        — global priority order (list of guard keys)
 *   guardrails.input.disabled.<capability> — guard keys switched off for that surface
 *
 * Centralising this means pipelines contain no policy about WHICH guards run — only HOW
 * they run. Adding/removing/reordering guards is pure config (Open/Closed).
 */
final class GuardChainResolver
{
    public function __construct(private readonly Config $config) {}

    /**
     * @template T of object
     * @param iterable<T> $guards each exposes key():string
     * @return list<T> ordered + filtered
     */
    public function order(string $stage, string $capability, iterable $guards): array
    {
        $byKey = [];
        foreach ($guards as $guard) {
            $byKey[$guard->key()] = $guard;
        }

        $order = (array) $this->config->get("guardrails.{$stage}.order", array_keys($byKey));
        $disabled = (array) $this->config->get("guardrails.{$stage}.disabled.{$capability}", []);

        $resolved = [];
        foreach ($order as $key) {
            if (isset($byKey[$key]) && ! in_array($key, $disabled, true)) {
                $resolved[] = $byKey[$key];
            }
        }

        return $resolved;
    }
}
