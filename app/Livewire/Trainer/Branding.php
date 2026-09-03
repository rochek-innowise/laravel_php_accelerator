<?php

declare(strict_types=1);

namespace App\Livewire\Trainer;

use App\Actions\Trainer\UpdateTrainerBranding;
use App\Exceptions\TrainerLogoException;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * FR-019: logo upload with preview and a primary-colour picker, applied immediately across every
 * user in this trainer's organisation. `TrainerProfilePolicy::updateBranding()` (owner-only,
 * Slice A) is the only authorization this screen needs.
 */
final class Branding extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $logo = null;

    public string $primaryColor = '';

    public function mount(): void
    {
        $trainer = $this->trainerProfile();

        $this->authorize('updateBranding', $trainer);

        $this->primaryColor = $trainer->primary_color ?? (string) config('branding.default_primary_color');
    }

    /**
     * Validated the moment it lands, before anything reaches the permanent disk — the same
     * discipline `ProfileForm::updatedPhoto()` follows.
     */
    public function updatedLogo(): void
    {
        $this->validateOnly('logo', $this->logoRules());
    }

    public function save(UpdateTrainerBranding $updateTrainerBranding): void
    {
        $trainer = $this->trainerProfile();

        $this->authorize('updateBranding', $trainer);

        $this->validate($this->rules());

        try {
            $updateTrainerBranding->handle($trainer, $this->logo, $this->primaryColor);
        } catch (TrainerLogoException $e) {
            $this->addError('logo', $e->getMessage());

            return;
        }

        $this->logo = null;

        // Own key, not `status`: the shared layout renders that one, and an in-place Livewire
        // re-render would show it twice. now(), not flash(): this component re-renders in place.
        session()->now('branding-status', 'Branding updated.');
    }

    /**
     * Named `resetBranding`, not `reset`: `Livewire\Component` already declares `reset(...$properties)`
     * (property reset, via the `InteractsWithProperties` trait) and PHP enforces signature
     * compatibility on an override even when the parent method comes from a trait — a same-named
     * method with a different signature is a fatal error, not a silent shadow.
     */
    public function resetBranding(UpdateTrainerBranding $updateTrainerBranding): void
    {
        $trainer = $this->trainerProfile();

        $this->authorize('updateBranding', $trainer);

        $updateTrainerBranding->reset($trainer);

        $this->logo = null;
        // Not `$trainer->fresh()?->primary_color`: that was a needless extra query, and `(string)
        // null` would silently become `''` if `fresh()` ever returned null. `reset()` just wrote
        // this column on this same instance, so it's already current.
        $this->primaryColor = (string) $trainer->primary_color;
        $this->resetValidation();

        session()->now('branding-status', 'Branding reset to the platform default.');
    }

    /**
     * Gap 12: this always derives the *actor's own* trainer profile — there is no reachable path
     * on this component where `$this->authorize('updateBranding', $trainer)` above is called
     * against a foreign trainer, so those three calls can never actually fail in practice. They
     * stay as defence-in-depth against a future change to this method (e.g. accepting a route
     * parameter) rather than as a live gate today; `TrainerProfilePolicy`'s own test
     * (`test_a_trainer_from_another_organisation_is_refused`) is what actually exercises the
     * "wrong trainer" case, at the policy level.
     */
    /**
     * Gap 13 (defence-in-depth): `branding.blade.php`'s swatch renders `$primaryColor` into an
     * inline `style` attribute *before* validation runs (`wire:model.live`, on every keystroke) —
     * `{{ }}` escapes `<`/`>`/quotes but not `;`, `{`, `}`, `(`, `)` or `:`, so an unvalidated value
     * reaching this far could inject arbitrary CSS into the swatch's own `style`. There is no
     * reachable path to that today (the property is typed `string`, not user-controllable except
     * through this same input, and `save()` re-validates before anything is persisted), but the
     * swatch previews on every keystroke, ahead of that validation — so this is checked again here
     * rather than trusted.
     */
    public function swatchColor(): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $this->primaryColor) === 1
            ? $this->primaryColor
            : (string) config('branding.default_primary_color');
    }

    protected function trainerProfile(): TrainerProfile
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $profile = $user->trainerProfile;

        abort_if($profile === null, 403);

        return $profile;
    }

    /** @return array<string, array<int, string>> */
    protected function rules(): array
    {
        return array_merge($this->logoRules(), [
            'primaryColor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
    }

    /**
     * `mimetypes` sniffs the file's actual content through finfo, unlike `mimes`, which trusts the
     * extension — a renamed script (or an SVG relabelled with a raster extension) would otherwise
     * pass. SVG is deliberately absent from `media.trainer_logos.mime_types` (Gap 8).
     *
     * @return array<string, array<int, string>>
     */
    protected function logoRules(): array
    {
        return [
            'logo' => [
                'nullable',
                'image',
                'mimetypes:'.implode(',', config('media.trainer_logos.mime_types')),
                'max:'.config('media.trainer_logos.max_kilobytes'),
            ],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'logo.mimetypes' => 'The logo must be a PNG or JPEG image. SVG and other file types are not accepted.',
            'logo.image' => 'The logo must be a PNG or JPEG image. SVG and other file types are not accepted.',
            'primaryColor.regex' => 'Enter a valid hex colour, e.g. #0EA5E9.',
        ];
    }

    public function render(): View
    {
        return view('livewire.trainer.branding', ['trainer' => $this->trainerProfile()]);
    }
}
