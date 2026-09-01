<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Trainer\CreateTrainerAccount;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** FR-006: Super Admin creates a trainer account. */
final class CreateTrainerForm extends Component
{
    public string $businessName = '';

    public string $trainerName = '';

    public string $email = '';

    public string $phone = '';

    public function save(CreateTrainerAccount $createTrainerAccount): void
    {
        // TODO(coder): authorize create, validate (unique email, required fields), call the action,
        // redirect with a confirmation.
        throw new \RuntimeException('Not implemented');
    }

    public function render(): View
    {
        return view('livewire.admin.create-trainer-form');
    }
}
