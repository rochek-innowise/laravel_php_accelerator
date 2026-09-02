<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\EnsureAccountIsActive;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // CreateNewUser stays registered even with registration disabled: Slice B's /join/{code}
        // component calls it so password rules and validation remain first-party (AD-004).
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        $this->registerViews();
        $this->registerAuthenticationPipeline();

        $this->registerRateLimiters();
    }

    /**
     * NFR-007. `login` is Fortify's own per-credential limiter; `fortify` covers every other
     * unauthenticated write it registers — password reset above all, which has no limiter of its
     * own and is the trainer onboarding path, so an unlimited POST there is a mail-bombing tool.
     * Read-only view routes are exempt so a reloaded sign-in page cannot lock anyone out.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('fortify', fn (Request $request): Limit => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(10)->by((string) $request->ip()));

        // `/join/{code}` is a GET, so this bounds *code probing* only. Account creation happens on
        // Livewire's update endpoint, which no route-level limiter here can reach — that half is
        // enforced inside the component, keyed by the same limiter name so both read as one rule.
        RateLimiter::for('join', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));
    }

    protected function registerViews(): void
    {
        Fortify::loginView(fn (): View => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn (): View => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request): View => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn (): View => view('auth.verify-email'));
    }

    /**
     * EnsureAccountIsActive sits behind the throttle so the FR-017 message cannot be probed
     * without rate limiting, and behind AttemptToAuthenticate so there is a user to inspect.
     */
    protected function registerAuthenticationPipeline(): void
    {
        Fortify::authenticateThrough(fn (Request $request): array => array_filter([
            config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            AttemptToAuthenticate::class,
            EnsureAccountIsActive::class,
            PrepareAuthenticatedSession::class,
        ]));
    }
}
