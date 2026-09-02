<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CoachStatus;
use App\Enums\Role;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\ResolvesAvailableTenants;
use App\Support\Tenancy\TrainerContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organisation once per request (AD-001).
 *
 * The rule that matters: a session value is a *cache of a permission*, never the permission. The
 * player branch re-validates `session('trainer_context_id')` against the live association set on
 * every request, so an association revoked mid-session stops resolving on the very next one.
 */
final class EnsureTrainerContext
{
    public const SESSION_KEY = 'trainer_context_id';

    public function __construct(
        protected TrainerContext $context,
        protected ResolvesAvailableTenants $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->context->set($this->resolveFor($user, $request));
        }

        return $next($request);
    }

    protected function resolveFor(User $user, Request $request): ?TrainerProfile
    {
        return match ($user->role) {
            // Fixed tenants: their own organisation, no switcher rendered.
            Role::Trainer => $user->trainerProfile,
            Role::Coach => $this->tenantForCoach($user),

            // No tenant. The read-only "inspect tenant" selection arrives in Slice D; until then a
            // Super Admin reads identity tables, which are unscoped anyway (AD-003).
            Role::SuperAdmin => null,

            Role::Player => $this->tenantForPlayer($user, $request),
        };
    }

    /** BR-006 guarantees at most one active row; an `invited` or released coach has no tenant. */
    protected function tenantForCoach(User $user): ?TrainerProfile
    {
        $profile = $user->coachProfile()->with('trainerProfile')->first();

        if ($profile === null || $profile->status !== CoachStatus::Active) {
            return null;
        }

        return $profile->trainerProfile;
    }

    protected function tenantForPlayer(User $user, Request $request): ?TrainerProfile
    {
        $available = $this->tenants->forUser($user);

        if ($available->isEmpty()) {
            return null;
        }

        $chosen = $request->hasSession()
            ? $request->session()->get(self::SESSION_KEY)
            : null;

        // Re-validated, never trusted: a stale or forged id simply is not in the live set.
        return $available->firstWhere('id', (int) $chosen) ?? $available->first();
    }
}
