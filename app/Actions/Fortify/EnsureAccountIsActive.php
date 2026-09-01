<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * FR-017: a non-active account cannot log in. Registered in the Fortify pipeline *after*
 * EnsureLoginIsNotThrottled, so repeated probing hits the throttle first and the message never
 * becomes an unthrottled account-enumeration oracle.
 */
final class EnsureAccountIsActive
{
    public function __invoke(Request $request, callable $next): mixed
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->status->canLogIn()) {
            auth()->logout();

            throw ValidationException::withMessages([
                Fortify::username() => __('Account deactivated. Contact support.'),
            ]);
        }

        return $next($request);
    }
}
