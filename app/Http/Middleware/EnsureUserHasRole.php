<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-004: a role reaches only its own screens; Super Admin passes everywhere. Refusals are thrown
 * rather than aborted so they land in the one place that audits authorization denials (A09).
 */
final class EnsureUserHasRole
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (empty($user)) {
            throw new AuthorizationException('Unauthenticated request reached a role-guarded route.');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $allowed = array_map(fn (string $role): Role => Role::from($role), $roles);

        if (! in_array($user->role, $allowed, true)) {
            throw new AuthorizationException(
                'Role ['.$user->role->value.'] may not reach a route limited to ['.implode(', ', $roles).'].'
            );
        }

        return $next($request);
    }
}
