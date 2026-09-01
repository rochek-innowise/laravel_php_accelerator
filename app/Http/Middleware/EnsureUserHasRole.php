<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FR-004: a role reaches only its own screens; Super Admin passes everywhere. */
final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (empty($user)) {
            abort(403);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $allowed = array_map(fn (string $role): Role => Role::from($role), $roles);

        if (! in_array($user->role, $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
