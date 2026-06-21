<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Models\Setting;
use App\Models\User;

/**
 * Single source of truth for what each subscription plan grants. Reads the config
 * defaults (config/tokens.php) layered with the admin `plan_overrides` Setting, so
 * limits are tunable live. Nothing else in the app should map a plan string to a
 * limit — controllers go through FeatureService, which leans on this.
 *
 * Max-pastors is delegated to StudyTiers (already admin-editable and used by the study
 * slider) so there is exactly one place that number lives.
 */
class PlanService
{
    /** Merged plan definitions: config defaults overlaid with the admin override. */
    public static function definitions(): array
    {
        $defaults = (array) config('tokens.plans', []);

        $raw = Setting::get('plan_overrides');
        $over = $raw !== null ? json_decode($raw, true) : null;
        if (! is_array($over)) {
            return $defaults;
        }

        // Shallow-merge per plan so an admin can tweak one key without redefining all.
        foreach ($over as $plan => $vals) {
            if (isset($defaults[$plan]) && is_array($vals)) {
                $defaults[$plan] = array_replace_recursive($defaults[$plan], $vals);
            }
        }

        return $defaults;
    }

    /** The definition for a single plan, falling back to the guest definition. */
    public static function definition(SubscriptionPlan $plan): array
    {
        $defs = self::definitions();

        return $defs[$plan->value] ?? $defs['guest'] ?? [
            'monthly_allowance' => 0, 'ads' => true, 'features' => [],
        ];
    }

    public static function monthlyAllowance(SubscriptionPlan $plan): int
    {
        return (int) (self::definition($plan)['monthly_allowance'] ?? 0);
    }

    /** Whether ads are shown to this plan. */
    public static function showsAds(SubscriptionPlan $plan): bool
    {
        return (bool) (self::definition($plan)['ads'] ?? true);
    }

    /** A named capability flag (voice/video/export/priority/...). */
    public static function hasFeature(SubscriptionPlan $plan, string $feature): bool
    {
        return (bool) (self::definition($plan)['features'][$feature] ?? false);
    }

    /** Max pastors a user may convene — delegated to the existing StudyTiers caps. */
    public static function maxPastors(?User $user): int
    {
        return StudyTiers::maxFor($user);
    }

    /** Persist an admin override map (only known plans/keys survive). */
    public static function saveOverrides(array $overrides): array
    {
        $clean = [];
        foreach (array_keys((array) config('tokens.plans', [])) as $plan) {
            if (isset($overrides[$plan]) && is_array($overrides[$plan])) {
                $clean[$plan] = $overrides[$plan];
            }
        }
        Setting::set('plan_overrides', json_encode($clean));

        return self::definitions();
    }
}
