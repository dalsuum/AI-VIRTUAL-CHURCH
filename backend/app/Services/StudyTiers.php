<?php

namespace App\Services;

use App\Models\ModuleManifest;
use App\Models\Setting;
use App\Models\User;

/**
 * Per-tier caps on how many pastors a worshipper may convene in a discussion.
 * Admin-editable (Setting key `study_agent_tiers`); enforced server-side so the
 * frontend slider can never be used to exceed a tier's limit.
 *
 * Tiers (extensible): guest (anonymous @guest.local), member (registered), premium
 * (full range). Premium is resolved from a future per-user flag; until that exists,
 * admins are treated as premium so the full slider is reachable for testing.
 */
class StudyTiers
{
    /** Sensible defaults before any admin edit. Always clamped to the platform 2–7. */
    public const DEFAULTS = ['guest' => 2, 'member' => 3, 'premium' => 7];

    public const TIERS = ['guest', 'member', 'premium'];

    /** The admin-configured (or default) cap per tier, clamped to platform bounds. */
    public static function caps(): array
    {
        $out = self::DEFAULTS;
        $raw = Setting::get('study_agent_tiers');
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach (self::TIERS as $tier) {
                    if (isset($decoded[$tier])) {
                        $out[$tier] = self::clamp((int) $decoded[$tier]);
                    }
                }
            }
        }

        return $out;
    }

    /** Persist new caps (validated + clamped). */
    public static function save(array $caps): array
    {
        $clean = self::caps();
        foreach (self::TIERS as $tier) {
            if (isset($caps[$tier])) {
                $clean[$tier] = self::clamp((int) $caps[$tier]);
            }
        }
        Setting::set('study_agent_tiers', json_encode($clean));

        return $clean;
    }

    /** Which tier a user falls into. Anonymous → guest. */
    public static function tierForUser(?User $user): string
    {
        if (! $user || str_ends_with((string) $user->email, '@guest.local')) {
            return 'guest';
        }
        // Future: a real `is_premium` flag/role. For now admins act as premium.
        if ($user->isAdmin() || ($user->is_premium ?? false)) {
            return 'premium';
        }

        return 'member';
    }

    /** The effective max pastors for this user — the lower of their tier cap and the
     *  module manifest's own max. */
    public static function maxFor(?User $user): int
    {
        $tierCap = self::caps()[self::tierForUser($user)] ?? self::DEFAULTS['guest'];
        $manifest = ModuleManifest::where('key', config('bible_study.module'))->first();
        $manifestMax = $manifest
            ? min(ModuleManifest::AGENT_COUNT_MAX, (int) $manifest->max_agent_count)
            : ModuleManifest::AGENT_COUNT_MAX;

        return max(ModuleManifest::AGENT_COUNT_MIN, min($tierCap, $manifestMax));
    }

    private static function clamp(int $n): int
    {
        return max(ModuleManifest::AGENT_COUNT_MIN, min(ModuleManifest::AGENT_COUNT_MAX, $n));
    }
}
