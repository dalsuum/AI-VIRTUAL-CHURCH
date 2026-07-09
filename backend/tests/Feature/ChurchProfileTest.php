<?php

namespace Tests\Feature;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Enums\ChurchRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChurchProfileTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;
    private User $elder;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $this->elder  = $this->makeUser();
        $this->member = $this->makeUser();
        $this->churchMember($this->elder, ChurchRole::ELDER);
        $this->churchMember($this->member, ChurchRole::MEMBER);
    }

    private function churchMember(User $u, ChurchRole $role): void
    {
        ChurchMembership::create([
            'church_id' => $this->church->id, 'user_id' => $u->id, 'role' => $role,
            'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    public function test_elder_updates_profile_and_member_cannot(): void
    {
        $payload = [
            'description'   => 'A welcoming church in Kalaymyo.',
            'address'       => "12 Bogyoke Road\nKalaymyo, Sagaing",
            'contact_email' => 'hello@grace.example',
            'website'       => 'https://grace.example',
            'socials'       => ['facebook' => 'https://facebook.com/gracechurch'],
            'languages'     => ['en', 'my'],
        ];

        $this->actingAs($this->elder, 'sanctum')
            ->putJson("/api/churches/{$this->church->id}/profile", $payload)
            ->assertOk()
            ->assertJsonFragment(['website' => 'https://grace.example'])
            ->assertJsonPath('languages', ['en', 'my']);

        // Members read the profile but cannot write it.
        $this->actingAs($this->member, 'sanctum')->getJson("/api/churches/{$this->church->id}")
            ->assertOk()
            ->assertJsonPath('socials.facebook', 'https://facebook.com/gracechurch')
            ->assertJsonPath('description', 'A welcoming church in Kalaymyo.');
        $this->actingAs($this->member, 'sanctum')
            ->putJson("/api/churches/{$this->church->id}/profile", ['description' => 'hijack'])
            ->assertForbidden();
    }

    public function test_partial_update_preserves_profile_and_operational_settings(): void
    {
        $this->church->update(['settings' => [
            'worship_day' => 'sunday',
            'profile'     => ['description' => 'Original description'],
        ]]);

        $this->actingAs($this->elder, 'sanctum')
            ->putJson("/api/churches/{$this->church->id}/profile", ['address' => 'New address'])
            ->assertOk()
            ->assertJsonPath('description', 'Original description')
            ->assertJsonPath('address', 'New address');

        // Operational settings outside the profile subtree are untouched.
        $this->assertSame('sunday', $this->church->fresh()->settings['worship_day']);
    }

    public function test_validation_rejects_bad_urls_languages_and_platforms(): void
    {
        $put = fn (array $p) => $this->actingAs($this->elder, 'sanctum')
            ->putJson("/api/churches/{$this->church->id}/profile", $p);

        $put(['website' => 'not-a-url'])->assertStatus(422);
        $put(['languages' => ['xx']])->assertStatus(422);                      // not in the registry
        $put(['socials' => ['myspace' => 'https://myspace.com/x']])->assertStatus(422); // unknown platform
        $put(['contact_email' => 'not-an-email'])->assertStatus(422);
    }

    public function test_name_and_timezone_update_but_slug_is_stable(): void
    {
        $this->actingAs($this->elder, 'sanctum')
            ->putJson("/api/churches/{$this->church->id}/profile", [
                'name' => 'Grace Community Church', 'timezone' => 'Asia/Yangon',
            ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Grace Community Church', 'timezone' => 'Asia/Yangon'])
            ->assertJsonFragment(['slug' => 'grace']);
    }

    public function test_logo_upload_replacement_and_authorization(): void
    {
        Storage::fake('public');

        // Member cannot upload.
        $this->actingAs($this->member, 'sanctum')
            ->postJson("/api/churches/{$this->church->id}/logo", ['image' => UploadedFile::fake()->image('a.png')])
            ->assertForbidden();

        // Non-image rejected.
        $this->actingAs($this->elder, 'sanctum')
            ->postJson("/api/churches/{$this->church->id}/logo", ['image' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf')])
            ->assertStatus(422);

        // Upload, then replace — the first file must not be orphaned.
        $this->actingAs($this->elder, 'sanctum')
            ->postJson("/api/churches/{$this->church->id}/logo", ['image' => UploadedFile::fake()->image('logo1.png')])
            ->assertOk()->assertJsonStructure(['logo_url']);
        $first = $this->church->fresh()->settings['profile']['logo_path'];
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->elder, 'sanctum')
            ->postJson("/api/churches/{$this->church->id}/logo", ['image' => UploadedFile::fake()->image('logo2.png')])
            ->assertOk();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($this->church->fresh()->settings['profile']['logo_path']);

        // The profile projection exposes the URL.
        $this->actingAs($this->member, 'sanctum')->getJson("/api/churches/{$this->church->id}")
            ->assertOk()->assertJsonStructure(['logo_url']);
    }

    public function test_banner_upload_is_separate_from_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->elder, 'sanctum')
            ->postJson("/api/churches/{$this->church->id}/banner", ['image' => UploadedFile::fake()->image('banner.jpg')])
            ->assertOk()->assertJsonStructure(['banner_url']);

        $profile = $this->church->fresh()->settings['profile'];
        $this->assertArrayHasKey('banner_path', $profile);
        $this->assertArrayNotHasKey('logo_path', $profile);
    }

    public function test_outsider_cannot_view_a_church_profile(): void
    {
        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/churches/{$this->church->id}")->assertForbidden();
    }
}
