<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Billing endpoints in the default test environment, where no Stripe key/price is
 * configured — so billing is "disabled" and self-serve checkout must degrade cleanly.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_is_disabled_without_stripe_config(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('billing_enabled', false);
    }

    public function test_checkout_is_unavailable_when_billing_disabled(): void
    {
        $user = $this->makeUser(['subscription_plan' => 'member']);

        $this->actingAs($user)->postJson('/api/subscription/checkout')
            ->assertStatus(503)
            ->assertJson(['message' => 'Subscriptions are not available right now.']);
    }

    public function test_cancel_rejects_a_non_premium_user(): void
    {
        $user = $this->makeUser(['subscription_plan' => 'member']);

        $this->actingAs($user)->postJson('/api/subscription/cancel')
            ->assertStatus(422)
            ->assertJson(['message' => 'No active premium subscription to cancel.']);
    }

    public function test_premium_user_is_recognised_as_premium(): void
    {
        $premium = $this->makeUser([
            'subscription_plan'   => 'premium',
            'subscription_status' => 'active',
        ]);

        $this->assertTrue($premium->isPremium());

        $this->actingAs($premium)->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('plan', 'premium')
            ->assertJsonPath('is_premium', true)
            ->assertJsonPath('monthly_allowance', 1000);
    }
}
