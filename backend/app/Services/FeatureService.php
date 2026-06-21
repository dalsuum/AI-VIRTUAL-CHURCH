<?php

namespace App\Services;

use App\Models\User;

/**
 * Thin, user-centric façade over PlanService. Controllers and views ask questions about
 * a *user* ("can this person see ads?") without ever knowing plan names or limits. This
 * is the seam to keep entitlement logic out of controllers.
 */
class FeatureService
{
    public function __construct(private ?User $user) {}

    public static function for(?User $user): self
    {
        return new self($user);
    }

    private function planOf(): \App\Enums\SubscriptionPlan
    {
        return $this->user?->plan() ?? \App\Enums\SubscriptionPlan::GUEST;
    }

    public function showsAds(): bool
    {
        return PlanService::showsAds($this->planOf());
    }

    public function canUseVoice(): bool   { return PlanService::hasFeature($this->planOf(), 'voice'); }
    public function canRenderVideo(): bool { return PlanService::hasFeature($this->planOf(), 'video'); }
    public function canExport(): bool      { return PlanService::hasFeature($this->planOf(), 'export'); }
    public function priorityQueue(): bool  { return PlanService::hasFeature($this->planOf(), 'priority'); }

    public function maxPastors(): int      { return PlanService::maxPastors($this->user); }

    public function monthlyAllowance(): int
    {
        return PlanService::monthlyAllowance($this->planOf());
    }

    public function remainingTokens(): int
    {
        return (int) ($this->user?->token_balance ?? 0);
    }
}
