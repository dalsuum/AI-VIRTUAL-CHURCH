<?php

namespace App\Enums;

/**
 * The ministry taxonomy for church groups. Values are canonical ids (stable,
 * never localized) — display names come from locale resources. CUSTOM covers
 * anything outside the standard ministries without schema changes.
 */
enum GroupType: string
{
    case BIBLE_STUDY = 'bible_study';
    case YOUTH       = 'youth';
    case CHILDREN    = 'children';
    case WOMEN       = 'women';
    case MEN         = 'men';
    case CHOIR       = 'choir';
    case PRAYER      = 'prayer';
    case CUSTOM      = 'custom';
}
