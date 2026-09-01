<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * FR-016: any user edits their own profile. Email, role, skill level and the created date are
 * read-only; the photo is validated on MIME and size before it ever reaches the disk.
 */
final class ProfileForm extends Component
{
    use WithFileUploads;

    public string $firstName = '';

    public string $lastName = '';

    public string $phone = '';

    public function save(): void
    {
        // TODO(coder): authorize update on the authenticated user, validate, persist, handle the
        // photo upload (resize, non-public disk, delete the partial upload on failure).
        throw new \RuntimeException('Not implemented');
    }

    public function render(): View
    {
        return view('livewire.profile-form');
    }
}
