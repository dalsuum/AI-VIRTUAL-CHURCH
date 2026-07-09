<?php

namespace App\Enums;

/**
 * The together-activity an invitation is for. Each maps to a session type that will
 * be created when the invitation is accepted (sessions land in later phases). New
 * activities are added here + a session factory — never a new invitation workflow.
 */
enum InvitationActivity: string
{
    case WORSHIP       = 'worship';
    case BIBLE_READING = 'bible_reading';
    case BIBLE_STUDY   = 'bible_study';
    case PRAYER        = 'prayer';
    case PASTOR_CHAT   = 'pastor_chat';
    case RADIO         = 'radio';

    /** LINK invitations only: joining a group, not a scheduled session. */
    case GROUP_MEMBERSHIP = 'group_membership';
}
