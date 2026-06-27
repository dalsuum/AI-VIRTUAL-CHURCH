<?php

namespace App\Enums;

/**
 * What a user is currently doing, surfaced by the presence system ("currently
 * reading", "talking with AI pastor", …). Null activity = simply online/idle.
 * New together-activities add a case here without touching the presence pipeline.
 */
enum PresenceActivity: string
{
    case READING  = 'reading';   // currently reading the Bible
    case STUDYING = 'studying';  // in a Bible study session
    case WORSHIP  = 'worship';   // in a worship / radio room
    case PASTOR   = 'pastor';    // talking with the AI pastor
    case RADIO    = 'radio';     // listening to Christian radio
}
