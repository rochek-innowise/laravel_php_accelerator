<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * FR-005: a Super Admin performs the same profile edit on another user. Role and status stay
 * read-only here — changing either belongs to Slice D's deactivate/role tooling.
 */
final class EditUserForm extends Component
{
    /** Locked: Livewire hydrates a model property from the request, so the target must not be client-settable. */
    #[Locked]
    public User $user;

    public string $firstName = '';

    public string $lastName = '';

    public string $phone = '';

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->firstName = (string) $user->first_name;
        $this->lastName = (string) $user->last_name;
        $this->phone = (string) $user->phone;
    }

    public function save(UpdateUserProfileInformation $updateProfileInformation): void
    {
        $this->authorize('update', $this->user);

        $updateProfileInformation->update($this->user, [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
        ]);

        session()->flash('status', 'Profile saved.');

        $this->redirectRoute('admin.users.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.edit-user-form');
    }
}
