<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\AuditAuthenticationEvents;
use App\Models\User;
use App\Support\Authorization\ChildAbilities;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Abilities whose policy already encodes its own Super Admin rule. The bypass must fall
     * through to them, or BR-016 (no Super-Admin-on-Super-Admin impersonation) is unenforceable.
     */
    protected const NOT_BYPASSABLE = ['impersonate'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GD rather than Imagick: both are available in the container, and profile photos need
        // nothing Imagick offers beyond it.
        $this->app->singleton(ImageManager::class, fn (): ImageManager => new ImageManager(new Driver));

        // One tenant per request/job, and `scoped` rather than `singleton` for two reasons: the
        // context now caches the resolved organisation set, and on a persistent runtime a guest
        // request reusing a worker would otherwise inherit the previous user's tenant — the
        // middleware only ever *sets* a context, it never clears one.
        $this->app->scoped(TrainerContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGates();
        $this->recordSuccessfulLogins();
        $this->auditAuthenticationEvents();
    }

    /**
     * Registration order matters: the child deny list runs first so a later grant can never
     * override it. Both callbacks return null to fall through to the policies.
     */
    protected function registerGates(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->is_child_account && ChildAbilities::denies($ability)) {
                return false;
            }

            return null;
        });

        // FR-007: joining an organisation is an ability rather than a policy method, because the
        // subject is the *link*, not a model the actor owns. A child login is refused by the deny
        // list above before this ever runs — one rule, one place (FR-011).
        Gate::define('trainer.associate', fn (User $user): bool => ! $user->is_child_account);

        // Super Admin bypasses policies, but never while impersonating — the acting identity is
        // the target, and an admin id leaking into the gate would be a privilege hole (AD-005).
        Gate::before(function (User $user, string $ability): ?bool {
            if (in_array($ability, self::NOT_BYPASSABLE, true)) {
                return null;
            }

            if ($user->isSuperAdmin() && ! $this->isImpersonating()) {
                return true;
            }

            return null;
        });
    }

    /**
     * Fortify fires Login from AttemptToAuthenticate, which runs *before* EnsureAccountIsActive —
     * so an attempt that is about to be refused reaches this listener. Skipping non-active
     * accounts keeps last_login_at meaning "last time this account actually got in", which is
     * what an admin reads when judging activity on a deactivated account (NFR-011).
     */
    protected function recordSuccessfulLogins(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User && ! $event->user->status->canLogIn()) {
                return;
            }

            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }

    /** NFR-011 / A09: the auth surface writes to the same audit trail as everything else. */
    protected function auditAuthenticationEvents(): void
    {
        Event::listen(Login::class, [AuditAuthenticationEvents::class, 'auditLogin']);
        Event::listen(Logout::class, [AuditAuthenticationEvents::class, 'auditLogout']);
        Event::listen(Failed::class, [AuditAuthenticationEvents::class, 'auditFailed']);
    }

    protected function isImpersonating(): bool
    {
        $request = request();

        if (! $request->hasSession()) {
            return false;
        }

        return $request->session()->has('impersonator_id');
    }
}
