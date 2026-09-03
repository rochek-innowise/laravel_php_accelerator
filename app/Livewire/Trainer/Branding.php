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
        $this->primaryColor = (string) $trainer->fresh()?->primary_color;
        $this->resetValidation();

        session()->now('branding-status', 'Branding reset to the platform default.');
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
