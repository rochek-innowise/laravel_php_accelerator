<?php

declare(strict_types=1);

namespace App\Livewire\Family;

use App\Actions\Family\ChildProfileData;
use App\Actions\Family\CreateChildProfile;
use App\Actions\Profile\StoreProfilePhoto;
use App\Enums\TrainerPlayerStatus;
use App\Exceptions\DuplicateChildProfileException;
use App\Exceptions\ProfilePhotoException;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * FR-008. The trainer picker renders as a yes/no toggle for a single-trainer family and a
 * checklist for a multi-trainer one (Decision 4's reading of "single vs multi-trainer parent"),
 * but both submit the same list of trainer ids to `CreateChildProfile`.
 */
final class ChildForm extends Component
{
    use WithFileUploads;

    /**
     * FR-008 requires gender at creation but does not enumerate values anywhere in the
     * requirements or brainstorming; kept to the same three values `PlayerProfileFactory` already
     * uses elsewhere in this codebase rather than inventing a longer list unprompted.
     *
     * @var list<string>
     */
    private const GENDERS = ['male', 'female', 'other'];

    public string $name = '';

    public string $birth_date = '';

    public string $gender = '';

    public ?string $school = null;

    public ?string $jersey_number = null;

    public ?string $emergency_contact = null;

    public ?TemporaryUploadedFile $photo = null;

    public bool $singleTrainerJoins = false;

    /** @var list<int> */
    public array $selectedTrainerIds = [];

    public bool $wantsLogin = false;

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** Set once a duplicate is detected; the confirm checkbox below sets $confirmDuplicate. */
    public bool $duplicateDetected = false;

    public bool $confirmDuplicate = false;

    public function mount(): void
    {
        $this->authorize('create', PlayerProfile::class);
    }

    /**
     * Validated the moment it lands, before anything reaches the permanent disk — the same
     * "validate on arrival" discipline `ProfileForm::updatedPhoto()` already applies.
     */
    public function updatedPhoto(): void
    {
        $this->validateOnly('photo', $this->photoRules());
    }

    public function save(CreateChildProfile $create, StoreProfilePhoto $storePhoto): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', 'string', Rule::in(self::GENDERS)],
            'school' => ['nullable', 'string', 'max:255'],
            'jersey_number' => ['nullable', 'string', 'max:10'],
            'emergency_contact' => ['nullable', 'string', 'max:65535'],
        ]);

        if ($this->wantsLogin) {
            $this->validate([
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'confirmed'],
            ]);
        }

        if ($this->photo !== null) {
            $this->validate($this->photoRules());
        }

        $data = new ChildProfileData(
            name: $this->name,
            birthDate: $this->birth_date,
            gender: $this->gender,
            school: $this->school !== null && $this->school !== '' ? $this->school : null,
            jerseyNumber: $this->jersey_number !== null && $this->jersey_number !== '' ? $this->jersey_number : null,
            emergencyContact: $this->emergency_contact !== null && $this->emergency_contact !== '' ? $this->emergency_contact : null,
            trainerProfileIds: $this->resolveSelectedTrainerIds(),
            confirmDuplicate: $this->confirmDuplicate,
            wantsLogin: $this->wantsLogin,
            loginEmail: $this->wantsLogin ? $this->email : null,
            loginPassword: $this->wantsLogin ? $this->password : null,
            loginPasswordConfirmation: $this->wantsLogin ? $this->password_confirmation : null,
        );

        try {
            $profile = $create->handle($this->actor(), $data);
        } catch (DuplicateChildProfileException $e) {
            $this->duplicateDetected = true;

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        // The photo needs the profile's own id for its storage path, so it can only be written
        // after CreateChildProfile has committed — a failure here leaves the child created without
        // a photo rather than losing the whole submission (the same tolerance ProfileForm::save()
        // already has for its own photo step).
        if ($this->photo !== null) {
            try {
                $storePhoto->handle($profile, $this->photo, withThumbnail: false);
            } catch (ProfilePhotoException $e) {
                session()->flash('status', $profile->name.' was added, but the photo could not be saved: '.$e->getMessage());
                $this->redirectRoute('family.index', navigate: true);

                return;
            }
        }

        session()->flash('status', $profile->name.' has been added to your family.');

        $this->redirectRoute('family.index', navigate: true);
    }

    /** @return list<int> */
    protected function resolveSelectedTrainerIds(): array
    {
        $available = $this->availableTrainers();

        if ($available->count() <= 1) {
            return $this->singleTrainerJoins ? $available->pluck('id')->all() : [];
        }

        // Re-derived against the family's own trainers, never trusted from the checklist: these
        // ids arrive on a public Livewire property, and `TrainerProfile` is identity-class and
        // unscoped, so a forged id would otherwise enrol this child into an organisation the
        // family has no relationship with — a cross-tenant write against NFR-010. Forged ids drop
        // inertly rather than raising, which keeps this from confirming that an id exists.
        // `Overview::addTrainer` guards its own picker the same way.
        return array_values(array_intersect(
            array_map('intval', $this->selectedTrainerIds),
            $available->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        ));
    }

    /**
     * Decision 4: the union of trainers already associated with any of the guardian's trainable
     * profiles (self + existing children) — offered here as candidates for the new child, reached
     * through the identity relation `trainerAssociations()` rather than a raw tenant-scoped query
     * (this spans every organisation the family belongs to, not "the current one").
     *
     * @return Collection<int, TrainerProfile>
     */
    public function availableTrainers(): Collection
    {
        $actor = $this->actor();

        return $actor->trainableProfiles()
            ->flatMap(fn (PlayerProfile $profile): Collection => $profile->trainerAssociations()
                ->where('status', TrainerPlayerStatus::Active)
                ->with('trainerProfile')
                ->get()
                ->pluck('trainerProfile'))
            ->filter()
            ->unique('id')
            ->values();
    }

    /** @return list<string> */
    public function genderOptions(): array
    {
        return self::GENDERS;
    }

    /**
     * `mimetypes` sniffs the file's actual content through finfo, unlike `mimes`, which trusts the
     * extension — a renamed script would otherwise pass. Optional: FR-008 lists photo among the
     * "optional" fields.
     *
     * @return array<string, array<int, string>>
     */
    protected function photoRules(): array
    {
        return [
            'photo' => [
                'nullable',
                'image',
                'mimetypes:'.implode(',', config('media.profile_photos.mime_types')),
                'max:'.config('media.profile_photos.max_kilobytes'),
            ],
        ];
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.family.child-form', [
            'availableTrainers' => $this->availableTrainers(),
            'genderOptions' => $this->genderOptions(),
        ]);
    }
}
