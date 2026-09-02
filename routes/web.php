<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfilePhotoController;
use App\Livewire\Admin\CreateTrainerForm;
use App\Livewire\Admin\EditUserForm;
use App\Livewire\Admin\UsersTable;
use App\Livewire\Family\ChildForm;
use App\Livewire\Family\Overview as FamilyOverview;
use App\Livewire\Family\PendingApprovals;
use App\Livewire\Join\RedeemShareLink;
use App\Livewire\ProfileForm;
use App\Livewire\Trainer\Coaches;
use App\Livewire\Trainer\ShareLinks;
use Illuminate\Support\Facades\Route;

// Fortify owns login, logout, password reset and email verification.
// Registration is disabled (AD-004): /join/{code} below is the only surface that creates accounts.

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Deliberately outside the `auth` group: a guest following an invitation is the common case, and
// the component branches on whether anyone is logged in. The ShareLink is the precondition of the
// form existing, so there is no route here whose only job is to refuse (AD-004).
// Throttled: a player link is permanent and unlimited-use (BR-008), so one leaked code would
// otherwise mean unbounded account creation and unbounded verification mail from this domain.
Route::get('/join/{code}', RedeemShareLink::class)
    ->middleware('throttle:join')
    ->name('join');

Route::middleware(['auth'])->group(function (): void {
    // Not behind `verified`: a user must be able to reach their profile and the verification
    // notice before verifying (Q-01.05a — verification is required to act, not to log in).
    Route::get('/profile', ProfileForm::class)->name('profile');

    // Signed, and the controller re-checks the policy: the signature bounds the link's lifetime,
    // it does not decide who may follow it. Not behind `verified` — the profile screen is not.
    Route::get('/users/{user}/photo/{variant?}', ProfilePhotoController::class)
        ->middleware('signed')
        ->name('users.photo');

    Route::middleware(['verified'])->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::view('/trainer', 'dashboards.trainer')->middleware('role:trainer')->name('trainer.dashboard');

        Route::prefix('trainer')->name('trainer.')->middleware('role:trainer')->group(function (): void {
            Route::get('/share-links', ShareLinks::class)->name('share-links');
            Route::get('/coaches', Coaches::class)->name('coaches');
        });
        Route::view('/coach', 'dashboards.coach')->middleware('role:coach')->name('coach.dashboard');
        Route::view('/player', 'dashboards.player')->middleware('role:player')->name('player.dashboard');

        // FR-008/FR-009/FR-010. Reached through identity relations, never a tenant-scoped query
        // (see the Slice C plan's Existing Context), so no `tenant` alias is needed here.
        Route::middleware('role:player')->group(function (): void {
            Route::prefix('family')->name('family.')->group(function (): void {
                Route::get('/', FamilyOverview::class)->name('index');
                Route::get('/children/create', ChildForm::class)->name('children.create');
            });

            Route::get('/approvals', PendingApprovals::class)->name('approvals.index');
        });

        Route::prefix('admin')->name('admin.')->middleware('role:super_admin')->group(function (): void {
            Route::get('/users', UsersTable::class)->name('users.index');
            Route::get('/users/create', CreateTrainerForm::class)->name('users.create');
            Route::get('/users/{user}/edit', EditUserForm::class)->name('users.edit');
        });
    });
});
