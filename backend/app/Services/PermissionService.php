<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Role-based permission checks for the staff console.
 *
 * Admin always has every permission — no database lookup needed.
 * Moderators and presenters each have a configurable subset stored as JSON
 * in the `role_permissions` settings key; the DEFAULTS kick in when that key
 * is absent so a fresh install behaves sensibly out of the box.
 */
class PermissionService
{
    /** Every permission key the system understands. */
    public const PERMISSIONS = [
        'dashboard.view',
        'services.view',
        'services.delete',
        'services.retry',
        'testimonies.view',
        'testimonies.approve',
        'testimonies.delete',
        'prayer_requests.view',
        'donors.view',
        'voice_studio.view',
        'users.view',
        'settings.view',
        'music_pool.view',
        'voice_training.view',
        'permissions.view',
        'language_review.view',
        'system.view',
    ];

    /** Only these roles appear in the permissions matrix. Admin is always full access. */
    public const CONFIGURABLE_ROLES = ['moderator', 'presenter'];

    public const DEFAULTS = [
        'moderator' => [
            'dashboard.view',
            'services.view',
            'services.retry',
            'testimonies.view',
            'testimonies.approve',
            'testimonies.delete',
            'prayer_requests.view',
            'donors.view',
            'voice_studio.view',
        ],
        'presenter' => [
            'dashboard.view',
            'voice_studio.view',
        ],
    ];

    /** Whether the user may perform $permission. Admin bypasses the DB check. */
    public static function can(User $user, string $permission): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $role = $user->role();

        return in_array($role, self::CONFIGURABLE_ROLES)
            && in_array($permission, static::forRole($role));
    }

    /** Abort 403 if the user lacks $permission. */
    public static function require(User $user, string $permission): void
    {
        abort_unless(static::can($user, $permission), 403, "Permission denied: {$permission}");
    }

    /** Flat permission list for a single role (from DB or defaults). */
    public static function forRole(string $role): array
    {
        $raw    = Setting::get('role_permissions');
        $stored = $raw !== null ? json_decode($raw, true) : [];

        if (is_array($stored) && array_key_exists($role, $stored)) {
            return $stored[$role];
        }

        return self::DEFAULTS[$role] ?? [];
    }

    /** Full permissions map for all configurable roles. */
    public static function all(): array
    {
        $raw    = Setting::get('role_permissions');
        $stored = ($raw !== null && is_string($raw)) ? json_decode($raw, true) : [];
        if (! is_array($stored)) {
            $stored = [];
        }

        $result = [];
        foreach (self::CONFIGURABLE_ROLES as $role) {
            $result[$role] = $stored[$role] ?? self::DEFAULTS[$role];
        }

        return $result;
    }

    /** Persist a new permissions map. Strips unknown keys and permissions. */
    public static function save(array $permissions): void
    {
        $clean = [];
        foreach (self::CONFIGURABLE_ROLES as $role) {
            if (isset($permissions[$role]) && is_array($permissions[$role])) {
                $clean[$role] = array_values(
                    array_intersect(self::PERMISSIONS, $permissions[$role])
                );
            }
        }
        Setting::set('role_permissions', json_encode($clean));
    }

    /** All permissions for a user — the full list for admins, role-specific for others. */
    public static function forUser(User $user): array
    {
        if ($user->isAdmin()) {
            return self::PERMISSIONS;
        }

        $role = $user->role();

        return in_array($role, self::CONFIGURABLE_ROLES)
            ? static::forRole($role)
            : [];
    }
}
