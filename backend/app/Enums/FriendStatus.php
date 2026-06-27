<?php

namespace App\Enums;

/**
 * State of the single canonical row in `friendships` for an unordered user pair.
 * A pair has at most one row: PENDING (request awaiting response), ACCEPTED
 * (mutual friends), or BLOCKED (one side blocked the other — see blocked_by).
 * Decline/cancel deletes the row rather than persisting a terminal state.
 */
enum FriendStatus: string
{
    case PENDING  = 'pending';
    case ACCEPTED = 'accepted';
    case BLOCKED  = 'blocked';
}
