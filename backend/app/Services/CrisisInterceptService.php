<?php

namespace App\Services;

use App\Models\CrisisIntercept;
use Illuminate\Support\Str;

/**
 * Runs BEFORE any AI generation is queued. If an intake contains crisis-indicating
 * language, the request is short-circuited to static, vetted resources and the LLM
 * pipeline is never invoked. This is a safety boundary, not a content feature.
 */
class CrisisInterceptService
{
    /**
     * Minimal illustrative keyword set. In production this should be a maintained,
     * reviewed list (and ideally a small dedicated classifier), not hardcoded here.
     */
    private const TRIGGERS = [
        'suicide', 'kill myself', 'end my life', 'self harm', 'self-harm',
        'hurt myself', 'overdose', "don't want to live", 'want to die',
    ];

    /**
     * @return array{intercepted: bool, resource?: string}
     */
    public function inspect(string $sessionToken, ?string $text): array
    {
        if (! $text) {
            return ['intercepted' => false];
        }

        $haystack = Str::lower($text);

        foreach (self::TRIGGERS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $resource = $this->resourceMessage();

                CrisisIntercept::create([
                    'session_hash'    => hash('sha256', $sessionToken),
                    'trigger_keyword' => $keyword,
                    'resource_served' => 'crisis_resource_card',
                ]);

                return ['intercepted' => true, 'resource' => $resource];
            }
        }

        return ['intercepted' => false];
    }

    private function resourceMessage(): string
    {
        // Served as static content. No AI involvement, no generated "pivot".
        return 'It sounds like you are carrying something very heavy right now. '
             . 'Please reach out to a trained person who can help directly. '
             . 'If you are in immediate danger, contact your local emergency number. '
             . 'You can also reach a crisis line in your country at findahelpline.com.';
    }
}
