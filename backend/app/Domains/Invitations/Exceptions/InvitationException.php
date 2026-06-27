<?php

namespace App\Domains\Invitations\Exceptions;

use RuntimeException;

/**
 * Raised on an illegal invitation transition (responding to a terminal/expired
 * invitation) or a forbidden actor. Carries the HTTP status the API returns —
 * 409 for a state conflict, 403 for an authority refusal.
 */
class InvitationException extends RuntimeException
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
