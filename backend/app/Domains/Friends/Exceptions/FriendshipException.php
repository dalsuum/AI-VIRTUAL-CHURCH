<?php

namespace App\Domains\Friends\Exceptions;

use RuntimeException;

/**
 * Raised when a friendship transition is illegal from the current state (e.g.
 * accepting a request that doesn't exist, friending a blocked user, unblocking
 * someone you didn't block). Carries the HTTP status the API should return —
 * 409 for a state conflict, 403 when a block/privacy rule forbids the action.
 */
class FriendshipException extends RuntimeException
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
