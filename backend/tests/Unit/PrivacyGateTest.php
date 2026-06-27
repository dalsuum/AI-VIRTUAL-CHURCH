<?php

namespace Tests\Unit;

use App\Domains\Accounts\Models\PrivacySetting;
use App\Domains\Accounts\Services\PrivacyGate;
use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Domains\Friends\Models\Friendship;
use App\Enums\FriendStatus;
use App\Enums\Visibility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyGateTest extends TestCase
{
    use RefreshDatabase;

    private PrivacyGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = app(PrivacyGate::class);
    }

    /** Persist the canonical friendship row in [min,max] order. */
    private function friend(User $a, User $b, FriendStatus $status, ?int $blockedBy = null): void
    {
        [$lo, $hi] = Friendship::orderedPair($a->id, $b->id);
        Friendship::create([
            'user_id'      => $lo,
            'friend_id'    => $hi,
            'status'       => $status,
            'requested_by' => $a->id,
            'blocked_by'   => $blockedBy,
        ]);
    }

    private function joinChurch(User $u, Church $c, string $status = 'active'): void
    {
        ChurchMembership::create([
            'church_id' => $c->id, 'user_id' => $u->id, 'role' => 'member',
            'status' => $status, 'joined_at' => now(),
        ]);
    }

    // --- canView truth table ---------------------------------------------------

    public function test_owner_always_sees_own_data_even_when_private(): void
    {
        $u = $this->makeUser();
        $this->assertTrue($this->gate->canView($u, $u, Visibility::PRIVATE));
    }

    public function test_public_is_visible_to_strangers(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser();
        $this->assertTrue($this->gate->canView($viewer, $owner, Visibility::PUBLIC));
    }

    public function test_private_is_hidden_from_everyone_else(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser();
        $this->friend($viewer, $owner, FriendStatus::ACCEPTED); // even friends can't see private
        $this->assertFalse($this->gate->canView($viewer, $owner, Visibility::PRIVATE));
    }

    public function test_friends_tier_requires_accepted_friendship(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser();

        $this->assertFalse($this->gate->canView($viewer, $owner, Visibility::FRIENDS));

        $this->friend($viewer, $owner, FriendStatus::PENDING);
        $this->assertFalse($this->gate->canView($viewer->fresh(), $owner, Visibility::FRIENDS));

        Friendship::query()->update(['status' => FriendStatus::ACCEPTED]);
        $this->assertTrue($this->gate->canView($viewer, $owner, Visibility::FRIENDS));
    }

    public function test_church_tier_visible_to_fellow_active_member(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser();
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);

        $this->joinChurch($viewer, $church);
        $this->assertFalse($this->gate->canView($viewer, $owner, Visibility::CHURCH));

        $this->joinChurch($owner, $church);
        $this->assertTrue($this->gate->canView($viewer, $owner, Visibility::CHURCH));
    }

    public function test_church_tier_ignores_inactive_membership(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser();
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $this->joinChurch($viewer, $church);
        $this->joinChurch($owner, $church, 'inactive');

        $this->assertFalse($this->gate->canView($viewer, $owner, Visibility::CHURCH));
    }

    public function test_block_overrides_public(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser();
        $this->friend($viewer, $owner, FriendStatus::BLOCKED, blockedBy: $owner->id);

        $this->assertFalse($this->gate->canView($viewer, $owner, Visibility::PUBLIC));
    }

    // --- presence / incognito --------------------------------------------------

    public function test_incognito_hides_presence_from_friends_but_not_self(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser();
        $this->friend($viewer, $owner, FriendStatus::ACCEPTED);
        PrivacySetting::create([
            'user_id' => $owner->id, 'presence_visibility' => Visibility::FRIENDS, 'incognito' => true,
        ]);

        $this->assertFalse($this->gate->canViewPresence($viewer, $owner->fresh()));
        $this->assertTrue($this->gate->canViewPresence($owner, $owner->fresh()));
    }

    // --- interaction / friend-only / block -------------------------------------

    public function test_friend_only_mode_blocks_non_friend_interaction(): void
    {
        $actor  = $this->makeUser();
        $target = $this->makeUser();
        PrivacySetting::create(['user_id' => $target->id, 'friend_only_mode' => true]);

        $this->assertFalse($this->gate->canInteract($actor, $target->fresh()));

        $this->friend($actor, $target, FriendStatus::ACCEPTED);
        $this->assertTrue($this->gate->canInteract($actor, $target->fresh()));
    }

    public function test_cannot_interact_when_blocked_or_with_self(): void
    {
        $actor  = $this->makeUser();
        $target = $this->makeUser();

        $this->assertFalse($this->gate->canInteract($actor, $actor));

        $this->friend($actor, $target, FriendStatus::BLOCKED, blockedBy: $target->id);
        $this->assertFalse($this->gate->canInteract($actor, $target));
    }

    public function test_default_visibility_is_friends_when_no_settings_row(): void
    {
        $viewer = $this->makeUser();
        $owner  = $this->makeUser(); // no privacy row

        $this->assertFalse($this->gate->canViewProfile($viewer, $owner));

        $this->friend($viewer, $owner, FriendStatus::ACCEPTED);
        $this->assertTrue($this->gate->canViewProfile($viewer, $owner));
    }
}
