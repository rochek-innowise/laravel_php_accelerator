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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// `role`, `status` and `is_child_account` are deliberately absent: they decide privilege, and a
// future `update($request->validated())` anywhere would otherwise be a role-escalation hole. The
// two actions that own them use forceFill; factories bypass the allow-list already.
/**
 * @property Role $role
 * @property UserStatus $status
 * @property bool $is_child_account
 * @property-read string $name
 */
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

    /**
     * Display name; the `name` column was dropped in favour of first/last (FR-016).
     *
     * @return Attribute<string, never>
     */
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

    /** People this user is responsible for — children, not their own self profile. */
    /** @return BelongsToMany<PlayerProfile, $this> */
    public function guardedPlayerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(PlayerProfile::class, 'player_guardians', 'guardian_user_id', 'player_profile_id')
            ->withPivot(['relationship', 'is_primary'])
            ->withTimestamps();
    }

    /** BR-022: a parent is emergent from guarding a child, never a role of its own. */
    public function isParent(): bool
    {
        return $this->guardedPlayerProfiles()->where('is_child', true)->exists();
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
