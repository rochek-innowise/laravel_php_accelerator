<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\TrainerPlayerStatus;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Which organisations can this account reach?" — asked by the context middleware and by the
 * trainer switcher, and answered here once per request.
 *
 * It exists because the same question used to be implemented twice, in two shapes, and a page load
 * paid for the whole membership derivation three times over. Selecting only the columns a member
 * may see keeps G-08 (branding, nothing else) true by construction rather than by convention:
 * address, website and description are never hydrated here.
 *
 * `primary_color` joined the projection in Slice D. FR-019 requires a trainer's brand colour to
 * apply to every user in that organisation, and the shared layout reads it from this object --
 * without it a player silently rendered the platform default. It is the same category of data as
 * `logo_path`, so G-08's intent is unchanged.
 */
final class ResolvesAvailableTenants
{
    public function __construct(protected TrainerContext $context) {}

    /** @return Collection<int, TrainerProfile> */
    public function forUser(User $user): Collection
    {
        $cached = $this->context->availableTenants();

        if ($cached !== null) {
            return $cached;
        }

        $profileIds = $user->trainableProfiles()->pluck('id')->all();

        $tenants = $profileIds === []
            ? collect()
            : $this->context->runAsSystem(
                fn (): Collection => TrainerProfile::query()
                    ->select('trainer_profiles.id', 'trainer_profiles.business_name', 'trainer_profiles.logo_path', 'trainer_profiles.primary_color')
                    ->join('trainer_players', 'trainer_players.trainer_profile_id', '=', 'trainer_profiles.id')
                    ->whereIn('trainer_players.player_profile_id', $profileIds)
                    ->where('trainer_players.status', TrainerPlayerStatus::Active)
                    ->whereNull('trainer_players.deleted_at')
                    ->groupBy('trainer_profiles.id', 'trainer_profiles.business_name', 'trainer_profiles.logo_path', 'trainer_profiles.primary_color')
                    ->orderByRaw('MIN(trainer_players.connected_at)')
                    ->orderBy('trainer_profiles.id')
                    ->get()
            );

        $this->context->setAvailableTenants($tenants);

        return $tenants;
    }
}
