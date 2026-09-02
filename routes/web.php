<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Livewire\Admin\CreateTrainerForm;
use App\Livewire\Admin\EditUserForm;
use App\Livewire\Admin\UsersTable;
use App\Livewire\ProfileForm;
use Illuminate\Support\Facades\Route;

// Fortify owns login, logout, password reset and email verification.
// Registration is disabled (AD-004): the only registration surface is /join/{code}, in Slice B.

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['auth'])->group(function (): void {
    // Not behind `verified`: a user must be able to reach their profile and the verification
    // notice before verifying (Q-01.05a — verification is required to act, not to log in).
    Route::get('/profile', ProfileForm::class)->name('profile');

    Route::middleware(['verified'])->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::view('/trainer', 'dashboards.trainer')->middleware('role:trainer')->name('trainer.dashboard');
        Route::view('/coach', 'dashboards.coach')->middleware('role:coach')->name('coach.dashboard');
        Route::view('/player', 'dashboards.player')->middleware('role:player')->name('player.dashboard');

        Route::prefix('admin')->name('admin.')->middleware('role:super_admin')->group(function (): void {
            Route::get('/users', UsersTable::class)->name('users.index');
            Route::get('/users/create', CreateTrainerForm::class)->name('users.create');
            Route::get('/users/{user}/edit', EditUserForm::class)->name('users.edit');
        });
    });
});
