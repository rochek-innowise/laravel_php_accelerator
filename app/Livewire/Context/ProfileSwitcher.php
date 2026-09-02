<?php

declare(strict_types=1);

namespace App\Livewire\Context;

use App\Enums\TrainerPlayerStatus;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * "Which family member am I acting as, here?" (design, The two switchers).
 *
 * Deliberately scoped to the *current* organisation: a parent whose child trains with Trainer B
 * but not Trainer A should not see that child while acting inside Trainer A.
 */
class ProfileSwitcher extends Component
{
    public const SESSION_KEY = 'player_profile_id';

    public function switch(int $playerProfileId): void
    {
        abort_unless($this->availableProfiles()->contains('id', $playerProfileId), 403);

        session([self::SESSION_KEY => $playerProfileId]);

        // URL::previous() rather than the raw Referer header: redirect()->to() accepts an absolute
        // off-host URL, which would make the switcher an open redirect.
        $this->redirect(URL::previous(route('dashboard')), navigate: true);
    }

    /** @return Collection<int, PlayerProfile> */
    public function availableProfiles(): Collection
    {
        $user = auth()->user();
        $tenant = app(TrainerContext::class)->get();

        if (! $user instanceof User || $tenant === null) {
            return collect();
        }

        $profiles = $user->trainableProfiles();

        if ($profiles->isEmpty()) {
            return collect();
        }

        $associated = TrainerPlayer::query()
            ->whereIn('player_profile_id', $profiles->pluck('id')->all())
            ->where('status', TrainerPlayerStatus::Active)
            ->pluck('player_profile_id')
            ->all();

        return $profiles->whereIn('id', $associated)->values();
    }

    public function render(): View
    {
        $profiles = $this->availableProfiles();
        $currentId = session(self::SESSION_KEY);

        return view('livewire.context.profile-switcher', [
            'profiles' => $profiles,
            'current' => $profiles->firstWhere('id', $currentId) ?? $profiles->first(),
            'visible' => $profiles->count() > 1,
        ]);
    }
}
