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
            auth()->logout();

            throw ValidationException::withMessages([
                Fortify::username() => __(UserStatus::DEACTIVATED_MESSAGE),
            ]);
        }

        return $next($request);
    }
}
