<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CoachStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Support\Tenancy\TenantScope;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

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

    /** @var Collection<int, PlayerProfile>|null */
    protected ?Collection $trainableProfiles = null;

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

    /**
     * The coach's current employment row.
     *
     * Deliberately tenant-blind: this is keyed on the coach's own `user_id`, an identity read with
     * no cross-tenant reach, and it is what resolves the coach's context in the first place —
     * scoping it would make the resolution circular.
     *
     * The ordering is not cosmetic. A released coach keeps their old row as history (G-11) and
     * gains a second one when re-hired, so an unordered `hasOne` returns whichever the engine
     * reaches first — in practice the oldest, released row, leaving a legitimately re-hired coach
     * with no tenant and, under fail-closed tenancy, an empty screen on every request.
     *
     * @return HasOne<CoachProfile, $this>
     */
    public function coachProfile(): HasOne
    {
        return $this->hasOne(CoachProfile::class)
            ->withoutGlobalScope(TenantScope::class)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [CoachStatus::Active->value])
            ->orderByDesc('id');
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
     * Everyone this account may act as: their own profile plus the children they guard.
     *
     * One source, used by both the "Who will train with X?" checklist and the profile switcher, so
     * the two can never disagree about who is in the family — the kind of drift that turns into a
     * child associated with a trainer nobody chose.
     *
     * Memoized per instance: this costs two queries, and it is read by the context middleware and
     * by both switchers on the same request. Without the cache a single page load paid for it
     * three times over.
     *
     * @return Collection<int, PlayerProfile>
     */
    public function trainableProfiles(): Collection
    {
        return $this->trainableProfiles ??= $this->playerProfile()->get()
            ->concat($this->guardedPlayerProfiles()->get())
            ->unique('id')
            ->values();
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

    /** Thumbnails are a deterministic suffix, so one column carries both variants. */
    public static function thumbnailPathFor(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return preg_replace('/\.'.preg_quote($extension, '/').'$/', '', $path).'_thumb.'.$extension;
    }

    public function photoThumbnailPath(): ?string
    {
        return empty($this->photo_path) ? null : static::thumbnailPathFor($this->photo_path);
    }

    /** Minted per render and short-lived: the URL is never stored, cached or emailed (AD-020). */
    public function photoUrl(string $variant = 'thumbnail'): ?string
    {
        if (empty($this->photo_path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'users.photo',
            now()->addMinutes((int) config('media.profile_photos.url_ttl_minutes')),
            ['user' => $this->getKey(), 'variant' => $variant],
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }
}
