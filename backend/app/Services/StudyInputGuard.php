<?php

namespace App\Services;

/**
 * Stage-1 safety pre-filter (server-side mirror of workers/core/safety_prefilter.py).
 * Cheaply blocks obvious prompt-injection / identity-probe attempts BEFORE a job is
 * dispatched, saving spend. The authoritative post-filter (classifier.review) still
 * runs on every generated turn in the worker. Conservative, to avoid false positives.
 */
class StudyInputGuard
{
    private const MAX_CHARS = 4000;

    private const PATTERNS = [
        '/ignore (all |the )?(previous|above|prior) (instructions|prompts?|messages?)/i',
        '/disregard (all |the )?(previous|above|prior)/i',
        '/reveal (your )?(system )?(prompt|instructions)/i',
        '/(what|print) (is|are)? ?(your )?(system )?(prompt|instructions)/i',
        '/you are (now|actually) (a|an|not)/i',
        '/pretend (to be|you are)/i',
        '/developer mode|jailbreak|\bDAN\b mode/i',
        '/override (the )?(moderator|rules|system)/i',
        '/reveal (the )?(real|true) (name|identity|pastor)/i',
        '/who inspired you/i',
    ];

    /** @return array{0:bool,1:string} [ok, reason] */
    public function check(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [false, 'Empty input.'];
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            return [false, 'Input too long.'];
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return [false, 'Your message looks like a prompt-injection attempt.'];
            }
        }

        return [true, ''];
    }
}
