<?php

namespace App\Domains\Bible\Exceptions;

use RuntimeException;

/**
 * Raised on an illegal reading action (enrolling while another plan is active, acting
 * with no active enrollment). Carries the HTTP status: 409 for a state conflict,
 * 403 for an authority refusal.
 */
class ReadingException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }
}
