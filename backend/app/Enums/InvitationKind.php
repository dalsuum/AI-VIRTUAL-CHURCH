<?php

namespace App\Enums;

/**
 * How an invitation reaches its audience. DIRECT is addressed to one known invitee
 * and terminates on their response. LINK is open — an unguessable token shared out
 * of band (URL, QR code, printed slip) that stays PENDING while being redeemed up
 * to max_uses times, until it expires or is revoked (the cancel transition).
 */
enum InvitationKind: string
{
    case DIRECT = 'direct';
    case LINK   = 'link';
}
