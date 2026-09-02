<?php

declare(strict_types=1);

namespace App\Livewire\Family;

use App\Actions\Family\ChildProfileData;
use App\Actions\Family\CreateChildProfile;
use App\Enums\TrainerPlayerStatus;
use App\Exceptions\DuplicateChildProfileException;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * FR-008. The trainer picker renders as a yes/no toggle for a single-trainer family and a
 * checklist for a multi-trainer one (Decision 4's reading of "single vs multi-trainer parent"),
 * but both submit the same list of trainer ids to `CreateChildProfile`.
 */
final class ChildForm extends Component
{
    public string $name = '';

    public string $birth_date = '';

    public ?string $school = null;

    public ?string $jersey_number = null;

    public ?string $emergency_contact = null;

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

    public function save(CreateChildProfile $create): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:today'],
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

        $data = new ChildProfileData(
            name: $this->name,
            birthDate: $this->birth_date,
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

        return array_map('intval', $this->selectedTrainerIds);
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
        ]);
    }
}
