<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Trainer\CreateTrainerAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** FR-006: Super Admin creates a trainer account. */
final class CreateTrainerForm extends Component
{
    public string $businessName = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $phone = '';

    public function mount(): void
    {
        $this->authorize('create', User::class);
    }

    public function save(CreateTrainerAccount $createTrainerAccount): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate([
            'businessName' => ['required', 'string', 'max:255'],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:50'],
        ]);

        $createTrainerAccount->handle([
            'business_name' => $validated['businessName'],
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
        ]);

        session()->flash('status', 'Trainer account created. An invitation has been sent.');

        $this->redirectRoute('admin.users.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.create-trainer-form');
    }
}
