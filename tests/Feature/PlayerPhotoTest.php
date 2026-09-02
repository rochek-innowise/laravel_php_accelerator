<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-008's photo half (Slice C, Decision 5): the same private-disk-plus-signed-route discipline
 * AD-020 already established for `User`, extended to `PlayerProfile` — full-size only, no
 * thumbnail variant to select between.
 */
final class PlayerPhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    #[Test]
    public function a_guardian_views_their_childs_photo_through_a_signed_route(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create([
            'photo_path' => 'profile-photos/1/photo.jpg',
        ]);
        Storage::disk('local')->put($child->photo_path, 'fake-bytes');

        $this->actingAs($guardian)->get($child->photoUrl())->assertOk();
    }

    #[Test]
    public function the_childs_own_login_may_also_view_it(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create([
            'user_id' => $childLogin->id,
            'photo_path' => 'profile-photos/1/photo.jpg',
        ]);
        Storage::disk('local')->put($child->photo_path, 'fake-bytes');

        $this->actingAs($childLogin)->get($child->photoUrl())->assertOk();
    }

    #[Test]
    public function a_signed_link_does_not_let_a_stranger_through(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create([
            'photo_path' => 'profile-photos/1/photo.jpg',
        ]);
        Storage::disk('local')->put($child->photo_path, 'fake-bytes');

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get($child->photoUrl())->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())->get($child->photoUrl())->assertOk();
    }

    #[Test]
    public function an_unsigned_request_is_refused(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create([
            'photo_path' => 'profile-photos/1/photo.jpg',
        ]);
        Storage::disk('local')->put($child->photo_path, 'fake-bytes');

        $this->actingAs($guardian)->get("/players/{$child->id}/photo")->assertForbidden();
    }

    #[Test]
    public function an_expired_link_is_refused(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create([
            'photo_path' => 'profile-photos/1/photo.jpg',
        ]);
        Storage::disk('local')->put($child->photo_path, 'fake-bytes');

        $expired = URL::temporarySignedRoute('players.photo', now()->subMinute(), ['player' => $child->id]);

        $this->actingAs($guardian)->get($expired)->assertForbidden();
    }

    #[Test]
    public function a_child_without_a_photo_gets_a_404(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create();

        $url = URL::temporarySignedRoute('players.photo', now()->addMinutes(5), ['player' => $child->id]);

        $this->actingAs($guardian)->get($url)->assertNotFound();
    }
}
