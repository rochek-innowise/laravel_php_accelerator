<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** FR-004: after login every role lands on its own dashboard. */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // TODO(coder): redirect via $request->user()->role->dashboardRoute().
        throw new \RuntimeException('Not implemented');
    }
}
