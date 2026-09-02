<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * FR-017 at the login boundary: refuse with a field-level error so the form can show it. The
 * per-request half of the same rule lives in EnsureAccountRemainsActive, which handles sessions
 * that were already open when the account was deactivated.
 */
final class EnsureAccountIsActive
{
    public function __invoke(Request $request, callable $next): mixed
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->status->canLogIn()) {
            // AttemptToAuthenticate already put the id in the session, and PrepareAuthenticatedSession
            // (which regenerates it) never runs on this path — so rotate here rather than leaving a
            // session id that was briefly associated with an authenticated user.
            //
            // Untested on purpose: with SESSION_DRIVER=array the id changes between test requests
            // anyway, so an assertion on it passes with or without this block. A test that cannot
            // fail is worse than none.
            auth()->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw ValidationException::withMessages([
                Fortify::username() => __(UserStatus::DEACTIVATED_MESSAGE),
            ]);
        }

        return $next($request);
    }
}
