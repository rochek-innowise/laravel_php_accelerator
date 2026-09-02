<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\TrainerProfile;
use Closure;
use Illuminate\Support\Collection;

/**
 * The active organisation for the current request, job or command (AD-001).
 *
 * Bound as a singleton. `EnsureTrainerContext` populates it from the request; everything outside
 * HTTP must populate it explicitly through `runFor()`, because a queued job has no session and a
 * fail-closed scope would otherwise let it succeed having done nothing at all (AD-002).
 */
final class TrainerContext
{
    protected ?TrainerProfile $tenant = null;

    /**
     * Suppression is not "no tenant" — it is "this query is deliberately tenant-blind". Kept
     * separate from a null tenant so the fail-closed branch can never be reached by accident.
     */
    protected bool $suppressed = false;

    /**
     * The organisations the current account can reach, resolved once per request.
     *
     * Both switchers ask the same question the middleware just answered, so caching it here is
     * what keeps a page load from re-deriving the whole membership set three times.
     *
     * @var Collection<int, TrainerProfile>|null
     */
    protected ?Collection $availableTenants = null;

    public function set(?TrainerProfile $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?TrainerProfile
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    /** @param  Collection<int, TrainerProfile>  $tenants */
    public function setAvailableTenants(Collection $tenants): void
    {
        $this->availableTenants = $tenants;
    }

    /** @return Collection<int, TrainerProfile>|null Null means "not resolved yet", not "none". */
    public function availableTenants(): ?Collection
    {
        return $this->availableTenants;
    }

    /**
     * Run work inside one organisation, then restore whatever was active before.
     *
     * The `finally` is the whole point: a throwing job that leaves a stale tenant behind would
     * hand the next job on the same worker process another organisation's context.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public function runFor(TrainerProfile $tenant, Closure $work): mixed
    {
        $previous = $this->tenant;
        $previouslySuppressed = $this->suppressed;

        $this->tenant = $tenant;

        // Suppression is cleared for the duration, or a `runFor` nested inside a `runAsSystem`
        // would read as scoped while running completely unscoped — the precise failure the
        // fail-closed design exists to prevent, and the kind that leaves no trace.
        $this->suppressed = false;

        try {
            return $work();
        } finally {
            $this->tenant = $previous;
            $this->suppressed = $previouslySuppressed;
        }
    }

    /**
     * Run work with the tenant scope suppressed, for paths that legitimately have no tenant yet:
     * ShareLink redemption by a guest, and jobs that declare themselves system-wide.
     *
     * This is deliberately a *different* escape from the Super-Admin-gated
     * `Model::withoutTenantScope()` query scope. Both are explicit and greppable; only this one is
     * reachable without an authenticated admin, so every call site is worth reading.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public function runAsSystem(Closure $work): mixed
    {
        $previous = $this->suppressed;
        $this->suppressed = true;

        try {
            return $work();
        } finally {
            $this->suppressed = $previous;
        }
    }
}
