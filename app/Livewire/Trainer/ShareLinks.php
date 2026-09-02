<?php

declare(strict_types=1);

namespace App\Livewire\Trainer;

use App\Actions\ShareLink\GeneratePlayerShareLink;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** FR-007: the static player link a trainer hands out (BR-008). */
class ShareLinks extends Component
{
    public ?ShareLink $link = null;

    /**
     * Read-only. `mount()` runs on a plain GET, which carries no CSRF token, so minting here made
     * `<img src="/trainer/share-links">` on any page a state-changing request against a logged-in
     * trainer. The screen renders an empty state instead and the trainer mints explicitly.
     */
    public function mount(GeneratePlayerShareLink $generate): void
    {
        $this->authorize('create', ShareLink::class);

        $this->link = $generate->existing($this->trainerProfile());
    }

    public function regenerate(GeneratePlayerShareLink $generate): void
    {
        $this->authorize('create', ShareLink::class);

        $replaced = $this->link !== null;
        $this->link = $generate->handle($this->trainerProfile(), $this->actor());

        session()->flash('status', $replaced
            ? 'A new invitation link is live. The previous one no longer works.'
            : 'Your invitation link is ready.');
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function trainerProfile(): TrainerProfile
    {
        $profile = $this->actor()->trainerProfile;

        abort_if($profile === null, 403);

        return $profile;
    }

    public function render(): View
    {
        return view('livewire.trainer.share-links', [
            'joinUrl' => $this->link !== null ? route('join', ['code' => $this->link->code]) : null,
        ]);
    }
}
