<?php

namespace App\Services\Chat\Capabilities;

use App\Services\Chat\Data\ChatContext;

/**
 * Faith-based encouragement for everyday struggles. The highest-sensitivity surface — the
 * orchestrator's input guardrail (crisis detection) is the real safety net; the persona
 * here reinforces boundaries and human referral.
 */
final class CounselingCapability extends AbstractCapability
{
    public function key(): string
    {
        return 'counseling';
    }

    public function systemPrompt(ChatContext $context): string
    {
        return implode(' ', [
            'You offer supportive, faith-based encouragement for everyday difficulties.',
            'You are NOT a licensed therapist: never diagnose, never replace professional',
            'care, and gently encourage reaching a trusted person or professional for',
            'serious or ongoing distress. Respond in the language given. Never use the',
            "person's name.",
        ]);
    }

    public function maxTokens(): int
    {
        return 700;
    }
}
