<?php

namespace App\Services\Chat\Contracts;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;

/**
 * Post-inference gate (content moderation, hallucination checks, username sanitisation,
 * sensitive-topic policy). Runs on the assembled model output before persistence. May
 * ALLOW with a sanitised replacement (verdict->text) or BLOCK with a safe message.
 */
interface OutputGuardrail
{
    public function review(string $modelOutput, ChatContext $context): GuardrailVerdict;
}
