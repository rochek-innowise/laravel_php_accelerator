<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\StartImpersonation;
use App\Actions\Admin\StopImpersonation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FR-012. Thin: both methods delegate entirely to their Action — `StartImpersonation` owns the
 * `Gate::authorize` call, `StopImpersonation` owns the restore-and-close logic shared with
 * `EnforceImpersonationTimeout`.
 */
final class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, StartImpersonation $action): RedirectResponse
    {
        $action->handle($request, $request->user(), $user);

        return redirect()->route('dashboard')->with(
            'status',
            __('Now viewing as :name.', ['name' => $user->name]),
        );
    }

    /**
     * No-ops (redirects to dashboard) if impersonator_id is absent — a stray POST here is not an
     * error, just nothing to stop.
     */
    public function stop(Request $request, StopImpersonation $action): RedirectResponse
    {
        if (! $request->session()->has('impersonator_id')) {
            return redirect()->route('dashboard');
        }

        $adminRestored = $action->handle($request);

        if (! $adminRestored) {
            return redirect()->route('login')->withErrors([
                'email' => __('Your account is no longer active. Please contact support.'),
            ]);
        }

        return redirect()->route('admin.users.index')->with(
            'status',
            __('Impersonation ended.'),
        );
    }
}
