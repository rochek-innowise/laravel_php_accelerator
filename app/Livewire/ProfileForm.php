<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Profile\UpdateRoleSpecificProfile;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * FR-016: any user edits their own profile plus the field set for their resolved profile.
 * Email, role, skill level and the created date are read-only. Photo upload is deliberately
 * absent here — it needs the storage disk, signed serving route and resize pipeline that belong
 * to the file-storage work.
 */
final class ProfileForm extends Component
{
    public string $firstName = '';

    public string $lastName = '';

    public string $phone = '';

    #[Locked]
    public bool $has_player_profile = false;

    #[Locked]
    public bool $is_parent = false;

    #[Locked]
    public ?string $skill_level = null;

    public ?string $school = null;

    public ?string $jersey_number = null;

    public ?string $emergency_contact = null;

    #[Locked]
    public bool $has_coach_profile = false;

    public ?string $bio = null;

    public ?string $credentials = null;

    public ?string $certifications = null;

    public bool $is_public = false;

    #[Locked]
    public bool $has_trainer_profile = false;

    public ?string $business_name = null;

    public ?string $address = null;

    public ?string $website = null;

    public ?string $description = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->firstName = (string) $user->first_name;
        $this->lastName = (string) $user->last_name;
        $this->phone = (string) $user->phone;

        if ($player = $user->playerProfile) {
            $this->has_player_profile = true;
            $this->skill_level = $player->skill_level;
            $this->school = $player->school;
            $this->jersey_number = $player->jersey_number;
            $this->emergency_contact = $player->emergency_contact;
            $this->is_parent = $user->ownedPlayerProfiles()->where('is_child', true)->exists();
        }

        if ($coach = $user->coachProfile) {
            $this->has_coach_profile = true;
            $this->bio = $coach->bio;
            $this->credentials = $coach->credentials;
            $this->certifications = $coach->certifications;
            $this->is_public = $coach->is_public;
        }

        if ($trainer = $user->trainerProfile) {
            $this->has_trainer_profile = true;
            $this->business_name = $trainer->business_name;
            $this->address = $trainer->address;
            $this->website = $trainer->website;
            $this->description = $trainer->description;
        }
    }

    public function save(UpdateUserProfileInformation $updateProfileInformation, UpdateRoleSpecificProfile $updateRoleSpecificProfile): void
    {
        $user = auth()->user();

        $this->authorize('update', $user);

        $updateProfileInformation->update($user, [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
        ]);

        if ($player = $user->playerProfile) {
            $this->authorize('update', $player);

            // Re-derived rather than trusted from the public property: it is a validation-visibility
            // flag on the client, not the authorization boundary.
            $isParent = $user->ownedPlayerProfiles()->where('is_child', true)->exists();
            $updateRoleSpecificProfile->handle($player, $this->validate($this->playerRules($isParent)));
        }

        if ($coach = $user->coachProfile) {
            $this->authorize('update', $coach);
            $updateRoleSpecificProfile->handle($coach, $this->validate($this->coachRules()));
        }

        if ($trainer = $user->trainerProfile) {
            $this->authorize('update', $trainer);
            $updateRoleSpecificProfile->handle($trainer, $this->validate($this->trainerRules()));
        }

        session()->flash('status', 'Profile updated.');
    }

    /** @return array<string, array<int, string>> */
    protected function playerRules(bool $isParent): array
    {
        $rules = [
            'school' => ['nullable', 'string', 'max:255'],
            'jersey_number' => ['nullable', 'string', 'max:10'],
        ];

        if ($isParent) {
            $rules['emergency_contact'] = ['nullable', 'string', 'max:65535'];
        }

        return $rules;
    }

    /** @return array<string, array<int, string>> */
    protected function coachRules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:65535'],
            'credentials' => ['nullable', 'string', 'max:65535'],
            'certifications' => ['nullable', 'string', 'max:65535'],
            'is_public' => ['boolean'],
        ];
    }

    /** @return array<string, array<int, string>> */
    protected function trainerRules(): array
    {
        return [
            // Required, not nullable: FR-006 demands it at creation and the column is NOT NULL.
            'business_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];
    }

    public function render(): View
    {
        return view('livewire.profile-form', ['user' => auth()->user()]);
    }
}
