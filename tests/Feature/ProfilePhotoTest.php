<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\ProfileForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-016 photo half: private disk, sniffed MIME, thumbnail, signed serving (AD-020). */
final class ProfilePhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_user_uploads_a_photo_and_gets_a_thumbnail(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->image('me.jpg', 800, 600))
            ->call('save')
            ->assertHasNoErrors();

        $path = $user->fresh()->photo_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists(User::thumbnailPathFor($path));
    }

    /** The thumbnail is square regardless of the source aspect ratio. */
    public function test_the_thumbnail_is_cropped_to_a_square(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->image('wide.jpg', 1200, 300))
            ->call('save');

        $thumbnail = Storage::disk('local')->get(User::thumbnailPathFor($user->fresh()->photo_path));
        $size = getimagesizefromstring((string) $thumbnail);
        $pixels = (int) config('media.profile_photos.thumbnail_pixels');

        $this->assertSame([$pixels, $pixels], [$size[0], $size[1]]);
    }

    /** A declared non-image never gets past validation. */
    public function test_a_non_image_upload_is_rejected_by_validation(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['photo']);

        $this->assertNull($user->fresh()->photo_path);
        Storage::disk('local')->assertDirectoryEmpty(config('media.profile_photos.directory'));
    }

    /**
     * A script renamed to .jpg. Note what this does *not* prove: UploadedFile::fake() derives the
     * MIME type from the extension, so in a test the file claims to be a JPEG and sails past the
     * mimetypes rule — real uploads are sniffed by finfo and stop there. What it does prove is the
     * second line of defence: the decoder rejects it, the user gets a field error rather than a
     * 500, and nothing is left on the disk.
     */
    public function test_a_renamed_script_fails_decoding_and_leaves_no_file(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "pwned";'))
            ->call('save')
            ->assertHasErrors(['photo']);

        $this->assertNull($user->fresh()->photo_path);
        Storage::disk('local')->assertDirectoryEmpty(config('media.profile_photos.directory'));
    }

    public function test_an_oversized_upload_is_rejected(): void
    {
        $user = User::factory()->create();
        $tooBig = (int) config('media.profile_photos.max_kilobytes') + 64;

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->image('huge.jpg')->size($tooBig))
            ->assertHasErrors(['photo']);

        $this->assertNull($user->fresh()->photo_path);
    }

    public function test_uploading_again_replaces_the_previous_pair(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(ProfileForm::class);
        $component->set('photo', UploadedFile::fake()->image('first.jpg'))->call('save');

        $first = $user->fresh()->photo_path;

        $component->set('photo', UploadedFile::fake()->image('second.jpg'))->call('save');

        $second = $user->fresh()->photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertMissing(User::thumbnailPathFor($first));
        Storage::disk('local')->assertExists($second);
    }

    public function test_removing_a_photo_deletes_both_files(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(ProfileForm::class);
        $component->set('photo', UploadedFile::fake()->image('me.jpg'))->call('save');

        $path = $user->fresh()->photo_path;

        $component->call('removePhoto');

        $this->assertNull($user->fresh()->photo_path);
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing(User::thumbnailPathFor($path));
    }

    public function test_the_photo_is_served_through_a_signed_route(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->image('me.jpg'))
            ->call('save');

        $this->actingAs($user->fresh())->get($user->fresh()->photoUrl())->assertOk();
    }

    public function test_an_unsigned_request_is_refused(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->image('me.jpg'))
            ->call('save');

        $this->actingAs($user->fresh())
            ->get("/users/{$user->id}/photo/thumbnail")
            ->assertForbidden();
    }

    /** A valid signature must not be enough: the policy decides who may follow the link. */
    public function test_a_signed_link_does_not_let_a_stranger_through(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        Livewire::actingAs($owner)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->image('me.jpg'))
            ->call('save');

        $url = $owner->fresh()->photoUrl();

        $this->actingAs($stranger)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())->get($url)->assertOk();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('photo', UploadedFile::fake()->image('me.jpg'))
            ->call('save');

        $expired = URL::temporarySignedRoute('users.photo', now()->subMinute(), [
            'user' => $user->id,
            'variant' => 'thumbnail',
        ]);

        $this->actingAs($user->fresh())->get($expired)->assertForbidden();
    }

    public function test_a_user_without_a_photo_gets_a_404(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute('users.photo', now()->addMinutes(5), [
            'user' => $user->id,
            'variant' => 'thumbnail',
        ]);

        $this->actingAs($user)->get($url)->assertNotFound();
    }
}
