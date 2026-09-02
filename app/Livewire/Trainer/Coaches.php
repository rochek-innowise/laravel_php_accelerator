<?php

declare(strict_types=1);

namespace App\Livewire\Trainer;

use App\Actions\Trainer\InviteCoach;
use App\Actions\Trainer\ReleaseCoach;
use App\Enums\ShareLinkType;
use App\Models\CoachProfile;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** FR-013: invite, track (Pending / Accepted / Expired) and resend. */
class Coaches extends Component
{
    public string $email = '';

    public string $note = '';

    public function invite(InviteCoach $invite): void
    {
        $this->authorize('invite', CoachProfile::class);

        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $invite->handle($this->trainerProfile(), $this->actor(), $this->email, $this->note ?: null);

        $this->reset('email', 'note');

        session()->flash('status', 'Invitation sent.');
    }

    public function resend(int $shareLinkId, InviteCoach $invite): void
    {
        $this->authorize('invite', CoachProfile::class);

        // The id is client-supplied. Tenant scoping stops it naming another organisation's row,
        // but not the trainer's *own* player link — resending that would revoke the permanent
        // invitation every player holds (BR-008) and mint a coach link with an empty target
        // address. The type filter is the guard; the policy check is the second half of it.
        $link = ShareLink::query()
            ->whereKey($shareLinkId)
            ->where('type', ShareLinkType::Coach)
            ->firstOrFail();

        $this->authorize('update', $link);

        $invite->resend($link, $this->trainerProfile(), $this->actor(), null);

        session()->flash('status', 'Invitation re-sent. The previous link no longer works.');
    }

    public function release(int $coachProfileId, ReleaseCoach $release): void
    {
        $profile = CoachProfile::query()->whereKey($coachProfileId)->firstOrFail();

        $this->authorize('update', $profile);

        $release->handle($profile);

        session()->flash('status', 'Coach released. They are now free to join another organisation.');
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
        $this->authorize('viewAny', CoachProfile::class);

        return view('livewire.trainer.coaches', [
            // Both queries are tenant-scoped by the global scope, so neither can name another
            // organisation's rows even if the ids were guessed.
            'coaches' => CoachProfile::query()->with('user')->latest('id')->get(),
            'invitations' => ShareLink::query()
                ->where('type', ShareLinkType::Coach)
                ->where('is_active', true)
                ->latest('id')
                ->get(),
        ]);
    }
}
