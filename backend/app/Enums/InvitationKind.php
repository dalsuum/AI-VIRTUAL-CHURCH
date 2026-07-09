<?php

namespace App\Enums;

/**
 * How an invitation reaches its audience. DIRECT is addressed to one known invitee
 * and terminates on their response. LINK is open — an unguessable token shared out
 * of band (URL, QR code, printed slip) that stays PENDING while being redeemed up
 * to max_uses times, until it expires or is revoked (the cancel transition).
 * REQUEST reverses the roles: the requester is the inviter (creator), the invitee is
 * nobody — whoever can manage the target group responds (accept = approve, decline =
 * deny) and the requester withdraws via the ordinary inviter-cancel rule.
 */
enum InvitationKind: string
{
    case DIRECT  = 'direct';
    case LINK    = 'link';
    case REQUEST = 'request';
}
