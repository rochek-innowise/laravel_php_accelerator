<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\PlayerProfile;
use App\Models\User;
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

    /** @var array<int, array{id: int, name: string, emergency_contact: string|null}> */
    public array $children = [];

    #[Locked]
    public ?string $skill_level = null;

    public ?string $school = null;

    public ?string $jersey_number = null;

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
        }

        if ($coach = $user->coachProfile) {
            $this->has_coach_profile = true;
            $this->bio = $coach->bio;
            $this->credentials = $coach->credentials;
            $this->certifications = $coach->certifications;
            $this->is_public = $coach->is_public;
        }

        // FR-016: a guardian keeps the emergency contact on each child's profile, where a trainer
        // looking after that child will actually find it — not on the guardian's own profile,
        // which a parent who does not train has no reason to own.
        $this->children = $user->guardedPlayerProfiles()
            ->where('is_child', true)
            ->orderBy('name')
            ->get()
            ->map(fn (PlayerProfile $child): array => [
                'id' => $child->id,
                'name' => $child->name,
                'emergency_contact' => $child->emergency_contact,
            ])
            ->all();

        if ($trainer = $user->trainerProfile) {
            $this->has_trainer_profile = true;
            $this->business_name = $trainer->business_name;
            $this->address = $trainer->address;
            $this->website = $trainer->website;
            $this->description = $trainer->description;
        }
    }

    public function save(UpdateUserProfileInformation $updateProfileInformation): void
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

            $player->update($this->validate($this->playerRules()));
        }

        $this->saveChildren($user);

        if ($coach = $user->coachProfile) {
            $this->authorize('update', $coach);
            $coach->update($this->validate($this->coachRules()));
        }

        if ($trainer = $user->trainerProfile) {
            $this->authorize('update', $trainer);
            $trainer->update($this->validate($this->trainerRules()));
        }

        session()->flash('status', 'Profile updated.');
    }

    /**
     * The ids travel to the client and a tampered snapshot really does change them — verified.
     * Two things stop that: each id is re-resolved through this user's guardianship, so an
     * unrelated profile is simply not found and the write is skipped silently rather than
     * refused (a 403 would confirm the profile exists); and the policy check behind it refuses
     * anyway if the resolution is ever loosened.
     */
    protected function saveChildren(User $user): void
    {
        if (empty($this->children)) {
            return;
        }

        $validated = $this->validate($this->childrenRules());

        foreach ($validated['children'] as $submitted) {
            $child = $user->guardedPlayerProfiles()->where('player_profiles.id', $submitted['id'])->first();

            if (empty($child)) {
                continue;
            }

            $this->authorize('update', $child);
            $child->update(['emergency_contact' => $submitted['emergency_contact']]);
        }
    }

    /** @return array<string, array<int, string>> */
    protected function playerRules(): array
    {
        $rules = [
            'school' => ['nullable', 'string', 'max:255'],
            'jersey_number' => ['nullable', 'string', 'max:10'],
        ];

        return $rules;
    }

    /** @return array<string, array<int, string>> */
    protected function childrenRules(): array
    {
        return [
            'children' => ['array'],
            'children.*.id' => ['required', 'integer'],
            'children.*.emergency_contact' => ['nullable', 'string', 'max:65535'],
        ];
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
