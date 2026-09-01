<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// `role`, `status` and `is_child_account` are deliberately absent: they decide privilege, and a
// future `update($request->validated())` anywhere would otherwise be a role-escalation hole. The
// two actions that own them use forceFill; factories bypass the allow-list already.
#[Fillable([
    'email',
    'password',
    'first_name',
    'last_name',
    'phone',
    'photo_path',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => UserStatus::class,
            'is_child_account' => 'boolean',
        ];
    }

    /** Display name; the `name` column was dropped in favour of first/last (FR-016). */
    protected function name(): Attribute
    {
        return Attribute::get(
            fn (): string => trim(($this->first_name ?? '').' '.($this->last_name ?? ''))
        );
    }

    /** @return HasOne<TrainerProfile, $this> */
    public function trainerProfile(): HasOne
    {
        return $this->hasOne(TrainerProfile::class);
    }

    /** @return HasOne<CoachProfile, $this> */
    public function coachProfile(): HasOne
    {
        return $this->hasOne(CoachProfile::class);
    }

    /** Profiles this user owns: their own self profile plus any children. */
    /** @return HasMany<PlayerProfile, $this> */
    public function ownedPlayerProfiles(): HasMany
    {
        return $this->hasMany(PlayerProfile::class, 'owner_user_id');
    }

    /** The profile this user personally trains under, if any. */
    /** @return HasOne<PlayerProfile, $this> */
    public function playerProfile(): HasOne
    {
        return $this->hasOne(PlayerProfile::class);
    }

    /**
     * A non-active account gets no reset link. The broker still reports success, so the response
     * stays identical for every address and no account-enumeration oracle appears.
     */
    public function sendPasswordResetNotification($token): void
    {
        if (! $this->status->canLogIn()) {
            return;
        }

        parent::sendPasswordResetNotification($token);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }
}
