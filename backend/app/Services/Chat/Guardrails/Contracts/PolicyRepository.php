<?php

namespace App\Services\Chat\Guardrails\Contracts;

/**
 * Separates POLICY (the rules: crisis keywords, injection patterns, banned terms, PII
 * regexes, theology expectations) from EXECUTION (the guards). A guard asks the repository
 * for its named policy and applies it; updating a policy never requires editing a guard.
 * The default implementation is config-backed, but this seam allows a DB- or remote-backed
 * policy source later (e.g. per-church policy) without touching any guard.
 */
interface PolicyRepository
{
    /**
     * Return the policy payload for $name (e.g. 'crisis', 'injection', 'pii'), or the
     * given default when undefined.
     *
     * @return array<string,mixed>
     */
    public function get(string $name, array $default = []): array;
}
