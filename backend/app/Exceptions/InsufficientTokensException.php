<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown by TokenService when a wallet lacks the tokens a reservation/spend requires. */
class InsufficientTokensException extends RuntimeException
{
    public function __construct(public int $required = 0, public int $available = 0)
    {
        parent::__construct('Insufficient tokens.');
    }
}
