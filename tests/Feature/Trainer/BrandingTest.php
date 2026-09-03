<?php

declare(strict_types=1);

namespace Tests\Feature\Trainer;

use App\Livewire\Trainer\Branding;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-019: logo upload with preview and a primary-colour picker, applied immediately across the
 * trainer's organisation. Branding lives on the public disk (Gap 12), unlike the private,
 * signed-route profile/child photo disk `ProfilePhotoTest` covers.
 */
final class BrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 'public' is the branding disk (Gap 12); 'local' is Livewire's own default temporary
        // upload disk before an Action ever runs — faking only the destination would still leave
        // real files behind under storage/app during a test run.
        Storage::fake('public');
        Storage::fake('local');
    }

    #[Test]
    public function the_owner_loads_the_screen_with_their_current_colour_prefilled(): void
    {
        [$user, $trainer] = $this->trainer(['primary_color' => '#112233']);

        Livewire::actingAs($user)
            ->test(Branding::class)
            ->assertSet('primaryColor', '#112233')
            ->assertOk();
    }

    #[Test]
    public function a_trainer_with_no_colour_yet_sees_the_platform_default(): void
    {
        [$user, $trainer] = $this->trainer(['primary_color' => null]);

        Livewire::actingAs($user)
            ->test(Branding::class)
            ->assertSet('primaryColor', (string) config('branding.default_primary_color'));
    }

    #[Test]
    public function a_logo_upload_is_resized_and_stored(): void
    {
        [$user, $trainer] = $this->trainer();

        Livewire::actingAs($user)
            ->test(Branding::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 1200, 900))
            ->set('primaryColor', '#0EA5E9')
            ->call('save')
            ->assertHasNoErrors();

        $path = $trainer->fresh()->logo_path;
        $maxPixels = (int) config('media.trainer_logos.max_pixels');

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('branding/'.$trainer->id.'/', $path);

        $size = getimagesizefromstring((string) Storage::disk('public')->get($path));
        $this->assertLessThanOrEqual($maxPixels, $size[0]);
        $this->assertLessThanOrEqual($maxPixels, $size[1]);
        // Aspect ratio preserved, not cropped to a square (a logo, not an avatar).
        $this->assertNotSame($size[0], $size[1]);
    }

    #[Test]
    public function uploading_again_replaces_the_previous_file(): void
    {
        [$user, $trainer] = $this->trainer();
        $component = Livewire::actingAs($user)->test(Branding::class);

        $component->set('logo', UploadedFile::fake()->image('first.png'))
            ->set('primaryColor', '#0EA5E9')
            ->call('save');

        $first = $trainer->fresh()->logo_path;

        $component->set('logo', UploadedFile::fake()->image('second.png'))
            ->set('primaryColor', '#0EA5E9')
            ->call('save');

        $second = $trainer->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function saving_the_colour_alone_leaves_an_existing_logo_untouched(): void
    {
        [$user, $trainer] = $this->trainer();
        $component = Livewire::actingAs($user)->test(Branding::class);

        $component->set('logo', UploadedFile::fake()->image('logo.png'))
            ->set('primaryColor', '#0EA5E9')
            ->call('save');

        $path = $trainer->fresh()->logo_path;

        $component->set('primaryColor', '#ABCDEF')->call('save');

        $trainer->refresh();
        $this->assertSame($path, $trainer->logo_path);
        $this->assertSame('#ABCDEF', $trainer->primary_color);
        Storage::disk('public')->assertExists($path);
    }

    /**
     * Gap 8: FR-019's acceptance text lists PNG/JPG/SVG, but an SVG is a scriptable document — a
     * stored-XSS vector when served back. Rejected with a field error naming the accepted types,
     * never a 500.
     */
    #[Test]
    public function an_svg_upload_is_rejected_with_a_field_error_naming_the_accepted_types(): void
    {
        [$user, $trainer] = $this->trainer();

        $component = Livewire::actingAs($user)
            ->test(Branding::class)
            ->set('logo', UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'))
            ->set('primaryColor', '#0EA5E9')
            ->call('save');

        $component->assertHasErrors(['logo']);
        $message = (string) $component->errors()->first('logo');

        $this->assertStringContainsString('PNG or JPEG', $message);
        $this->assertNull($trainer->fresh()->logo_path);
        Storage::disk('public')->assertDirectoryEmpty(config('media.trainer_logos.directory'));
    }

    #[Test]
    public function an_oversized_upload_is_rejected(): void
    {
        [$user, $trainer] = $this->trainer();
        $tooBig = (int) config('media.trainer_logos.max_kilobytes') + 64;

        Livewire::actingAs($user)
            ->test(Branding::class)
            ->set('logo', UploadedFile::fake()->image('huge.png')->size($tooBig))
            ->assertHasErrors(['logo']);

        $this->assertNull($trainer->fresh()->logo_path);
    }

    /**
     * A script renamed to .png: the second line of defence after the MIME sniff, mirroring
     * `ProfilePhotoTest`'s equivalent case. The upload should never reach the disk permanently and
     * must never surface as a 500.
     */
    #[Test]
    public function a_renamed_script_fails_decoding_and_leaves_no_file(): void
    {
        [$user, $trainer] = $this->trainer();

        Livewire::actingAs($user)
            ->test(Branding::class)
            ->set('logo', UploadedFile::fake()->createWithContent('shell.png', '<?php echo "pwned";'))
            ->set('primaryColor', '#0EA5E9')
            ->call('save')
            ->assertHasErrors(['logo']);

        $this->assertNull($trainer->fresh()->logo_path);
        Storage::disk('public')->assertDirectoryEmpty(config('media.trainer_logos.directory'));
    }

    #[Test]
    public function an_invalid_hex_colour_is_rejected(): void
    {
        [$user, $trainer] = $this->trainer();

        foreach (['blue', '#12345', '#GGGGGG', '123456', ''] as $invalid) {
            Livewire::actingAs($user)
                ->test(Branding::class)
                ->set('primaryColor', $invalid)
                ->call('save')
                ->assertHasErrors(['primaryColor']);
        }

        $this->assertNotSame('blue', $trainer->fresh()->primary_color);
    }

    #[Test]
    public function a_valid_hex_colour_is_accepted_case_insensitively(): void
    {
        [$user, $trainer] = $this->trainer();

        Livewire::actingAs($user)
            ->test(Branding::class)
            ->set('primaryColor', '#aAbBcC')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('#aAbBcC', $trainer->fresh()->primary_color);
    }

    #[Test]
    public function reset_clears_the_logo_and_restores_the_default_colour(): void
    {
        [$user, $trainer] = $this->trainer();
        $component = Livewire::actingAs($user)->test(Branding::class);

        $component->set('logo', UploadedFile::fake()->image('logo.png'))
            ->set('primaryColor', '#ABCDEF')
            ->call('save');

        $path = $trainer->fresh()->logo_path;
        $this->assertNotNull($path);

        $component->call('resetBranding');

        $trainer->refresh();
        $this->assertNull($trainer->logo_path);
        $this->assertSame(config('branding.default_primary_color'), $trainer->primary_color);
        Storage::disk('public')->assertMissing($path);
        $component->assertSet('primaryColor', config('branding.default_primary_color'));
    }

    #[Test]
    public function the_audit_trail_records_the_branding_update_and_reset(): void
    {
        [$user, $trainer] = $this->trainer();

        Livewire::actingAs($user)
            ->test(Branding::class)
            ->set('primaryColor', '#0EA5E9')
            ->call('save');

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'trainer-branding.updated',
        ]);

        Livewire::actingAs($user)->test(Branding::class)->call('resetBranding');

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'trainer-branding.reset',
        ]);
    }

    #[Test]
    public function a_non_trainer_is_refused_the_screen(): void
    {
        foreach ([User::factory()->coach()->create(), User::factory()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('trainer.branding'))->assertForbidden();
        }
    }

    /** @return array{0: User, 1: TrainerProfile} */
    protected function trainer(array $attributes = []): array
    {
        $user = User::factory()->trainer()->create();

        return [$user, TrainerProfile::factory()->create(array_merge(['user_id' => $user->id], $attributes))];
    }
}
