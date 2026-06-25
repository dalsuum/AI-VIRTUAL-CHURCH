<?php

namespace App\Services\Chat\Contracts;

use App\Services\Chat\Data\ChatContext;
use App\Services\Inference\Data\InferenceRequest;

/**
 * Assembles the final InferenceRequest from the capability persona, conversation history,
 * retrieved knowledge and the current user message. This is the ONLY place where those
 * parts are composed, so prompt structure (and the data/instruction separation that
 * mitigates injection) lives in one auditable spot. It performs no inference itself.
 */
interface PromptBuilder
{
    public function build(ChatContext $context): InferenceRequest;
}
