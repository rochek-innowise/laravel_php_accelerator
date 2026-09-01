<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * FR-016: any user edits their own profile. Email, role, skill level and the created date are
 * read-only. Photo upload is deliberately absent here — it needs the storage disk, signed
 * serving route and resize pipeline that belong to the file-storage work.
 */
final class ProfileForm extends Component
{
    public string $firstName = '';

    public string $lastName = '';

    public string $phone = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->firstName = (string) $user->first_name;
        $this->lastName = (string) $user->last_name;
        $this->phone = (string) $user->phone;
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

        session()->flash('status', 'Profile updated.');
    }

    public function render(): View
    {
        return view('livewire.profile-form', ['user' => auth()->user()]);
    }
}
