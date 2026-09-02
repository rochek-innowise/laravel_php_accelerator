<?php

declare(strict_types=1);

namespace App\Livewire\Context;

use App\Enums\Role;
use App\Http\Middleware\EnsureTrainerContext;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\ResolvesAvailableTenants;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * "Which organisation am I in?" (design, The two switchers).
 *
 * G-08: business name and logo, nothing else. No counts, no badges, no aggregated notifications.
 * "No unified view" is a rule about training data; the list of organisations a person personally
 * joined is that person's own data, but an event count beside a name is a second organisation's
 * data bleeding into the first's context.
 */
class TrainerSwitcher extends Component
{
    public function switch(int $trainerProfileId): void
    {
        $available = $this->availableTenants();

        // The posted id is re-validated against the live set. A session value is a cache of a
        // permission, never the permission — so a forged id is refused, not quietly honoured.
        abort_unless($available->contains('id', $trainerProfileId), 403);

        session([EnsureTrainerContext::SESSION_KEY => $trainerProfileId]);

        // URL::previous() rather than the raw Referer header: redirect()->to() accepts an absolute
        // off-host URL, which would make the switcher an open redirect.
        $this->redirect(URL::previous(route('dashboard')), navigate: true);
    }

    /**
     * The set `EnsureTrainerContext` already resolved this request, reused rather than recomputed.
     *
     * It carries exactly `id`, `business_name` and `logo_path` — the whole of G-08. Falling back to
     * the middleware's own resolver keeps the component correct when it is tested in isolation,
     * and that path caches into the context too, so it can never run twice either.
     *
     * @return Collection<int, TrainerProfile>
     */
    public function availableTenants(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->role !== Role::Player) {
            return collect();
        }

        return app(TrainerContext::class)->availableTenants()
            ?? app(ResolvesAvailableTenants::class)->forUser($user);
    }

    public function render(): View
    {
        $tenants = $this->availableTenants();

        return view('livewire.context.trainer-switcher', [
            'tenants' => $tenants,
            'current' => app(TrainerContext::class)->get(),
            // One organisation is not a choice; rendering a switcher for it is noise.
            'visible' => $tenants->count() > 1,
        ]);
    }
}
