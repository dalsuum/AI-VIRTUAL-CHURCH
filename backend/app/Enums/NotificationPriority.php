<?php

namespace App\Enums;

/**
 * Delivery priority for community notifications. Priority — not the calling code —
 * decides which channels fire, so the mapping lives in exactly one place. Today only
 * database + mail are wired; WebSocket and push slot into channels() in Phase 6
 * without touching any notification class.
 *
 *   critical → in-app + email (e.g. "worship starts in 5 minutes")
 *   high     → in-app + email (e.g. invitation received, pastor replied)
 *   normal   → in-app only    (e.g. friend request, reminder)
 *   low      → in-app only    (e.g. friend came online, request accepted)
 */
enum NotificationPriority: string
{
    case CRITICAL = 'critical';
    case HIGH     = 'high';
    case NORMAL   = 'normal';
    case LOW      = 'low';

    /** Base channel set for this priority. The notification drops 'mail' if the
     *  recipient has no email. Database is the in-app inbox and is always present. */
    public function channels(): array
    {
        return match ($this) {
            self::CRITICAL, self::HIGH => ['database', 'mail'],
            self::NORMAL, self::LOW    => ['database'],
        };
    }
}
