<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Http\Request;

/**
 * FR-017: a non-active account cannot log in. Registered in the Fortify pipeline *after*
 * EnsureLoginIsNotThrottled, so the message cannot be probed as an account-enumeration oracle.
 */
final class EnsureAccountIsActive
{
    public function __invoke(Request $request, callable $next): mixed
    {
        // TODO(coder): reject non-active statuses with ValidationException carrying the exact
        // FR-017 copy: "Account deactivated. Contact support."
        throw new \RuntimeException('Not implemented');
    }
}
