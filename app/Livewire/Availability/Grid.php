<?php

declare(strict_types=1);

namespace App\Livewire\Availability;

use App\Actions\Availability\SaveAvailability;
use App\Enums\Role;
use App\Livewire\Context\ProfileSwitcher;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\User;
use App\Policies\AvailabilityPolicy;
use App\Services\Availability\AvailabilityResolver;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * FR-014/FR-015's "Best Times" grid, shared by both `/availability` (role:player) and
 * `/coach/my-times` (role:coach) — the same component branches on `auth()->user()->role`, per the
 * Slice D plan. The coach branch renders no default/override toggle at all: `$trainerProfileId` is
 * always the coach's own, fixed employer.
 *
 * Authorization is called directly against `AvailabilityPolicy`, never through the generic
 * `$this->authorize('update', $subject)`: `PlayerProfile`/`CoachProfile` already have their own
 * registered policies (Slice A), and Laravel's Gate resolves an ability purely from the subject's
 * model class — the generic call would silently hit those policies, never this one (see
 * `AvailabilityPolicy`'s own docblock).
 */
final class Grid extends Component
{
    /** Locked: server-resolved in mount(), never client-settable — the same reasoning as EditUserForm's own `$user`. */
    #[Locked]
    public PlayerProfile|CoachProfile $subject;

    /** Null means "editing the default set" — either a Player with no resolved trainer context, or a coach's own fixed employer that happens to be unresolved (should not occur in practice). */
    #[Locked]
    public ?int $trainerProfileId = null;

    #[Locked]
    public bool $isCoach = false;

    /**
     * A plain public (not `#[Locked]`) property, bound by `wire:model` from the browser — a
     * tampered payload can omit any key entirely, so every key here is optional as far as PHP (and
     * PHPStan) is concerned, whatever the happy-path shape actually looks like.
     *
     * @var list<array{day_of_week?: int|string, start_time?: string, end_time?: string}>
     */
    public array $ranges = [];

    public function mount(): void
    {
        $actor = $this->actor();

        if ($actor->role === Role::Coach) {
            $this->mountCoach($actor);
        } else {
            $this->mountPlayer($actor);
        }

        $this->authorizeSubject();

        $this->loadRanges();
    }

    public function addRange(): void
    {
        $this->ranges[] = ['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '20:00'];
    }

    public function removeRange(int $index): void
    {
        unset($this->ranges[$index]);
        $this->ranges = array_values($this->ranges);
    }

    public function save(SaveAvailability $action): void
    {
        $this->authorizeSubject();

        $action->handle($this->subject, $this->trainerProfileId, $this->validatedRanges());

        $this->loadRanges();

        session()->flash('status', 'Availability saved.');
    }

    /** Player/parent only, and only while there is an active override slot to reset. */
    public function resetToDefault(SaveAvailability $action): void
    {
        abort_if($this->isCoach || $this->trainerProfileId === null, 403);

        $this->authorizeSubject();

        $action->handle($this->subject, $this->trainerProfileId, []);

        $this->loadRanges();

        session()->flash('status', 'Reset to your default times.');
    }

    public function isUsingDefault(): bool
    {
        return app(AvailabilityResolver::class)->isUsingDefault($this->subject, $this->trainerProfileId);
    }

    protected function mountCoach(User $actor): void
    {
        $coach = $actor->coachProfile;

        abort_if($coach === null, 404);

        $this->subject = $coach;
        $this->trainerProfileId = $coach->trainer_profile_id;
        $this->isCoach = true;
    }

    /**
     * The profile named by the session key is re-validated against the actor's own
     * `trainableProfiles()` — never trusted blindly. A forged/foreign id (a non-guardian's session
     * pointed at someone else's child) is refused with 403, not silently substituted; a genuinely
     * absent selection falls back to the actor's own first reachable profile.
     */
    protected function mountPlayer(User $actor): void
    {
        $profiles = $actor->trainableProfiles();
        $selectedId = session(ProfileSwitcher::SESSION_KEY);

        if ($selectedId !== null) {
            $profile = $profiles->firstWhere('id', $selectedId);
            abort_if($profile === null, 403);
        } else {
            $profile = $profiles->first();
            abort_if($profile === null, 404);
        }

        $this->subject = $profile;
        $this->trainerProfileId = app(TrainerContext::class)->id();
    }

    protected function loadRanges(): void
    {
        $this->ranges = app(AvailabilityResolver::class)
            ->resolve($this->subject, $this->trainerProfileId)
            ->filter(fn ($row): bool => $row->is_available)
            ->map(fn ($row): array => [
                'day_of_week' => $row->day_of_week->value,
                'start_time' => substr((string) $row->start_time, 0, 5),
                'end_time' => substr((string) $row->end_time, 0, 5),
            ])
            ->values()
            ->all();
    }

    /**
     * Gap 7: `$start`/`$end` used to be concatenated straight into a `TIME` literal on nothing
     * more than a non-empty check and a *string* `<=` comparison — `'9:00' > '10:00'` lexically,
     * and `'abc'`/`'abd'` passes both checks and reaches MariaDB as `'abc:00'`, a 500 rather than a
     * field error. A browser's `<input type="time" step="...">` can also submit `HH:MM:SS`, which
     * concatenation would turn into `'17:00:00:00'` the same way. `date_format:H:i` rejects
     * anything not already exactly that shape before either string ever reaches a comparison or a
     * query, and the times are then compared as actual times (via `strtotime`), never as strings.
     *
     * @return list<array{day_of_week: int, start_time: string, end_time: string, is_available: bool}>
     */
    protected function validatedRanges(): array
    {
        $result = [];

        foreach ($this->ranges as $index => $range) {
            $day = filter_var($range['day_of_week'] ?? null, FILTER_VALIDATE_INT);
            $start = trim((string) ($range['start_time'] ?? ''));
            $end = trim((string) ($range['end_time'] ?? ''));

            if ($day === false || $day < 0 || $day > 6) {
                throw ValidationException::withMessages(["ranges.{$index}.day_of_week" => 'Choose a day.']);
            }

            if (! $this->isValidTime($start)) {
                throw ValidationException::withMessages(["ranges.{$index}.start_time" => 'Enter a valid time.']);
            }

            if (! $this->isValidTime($end)) {
                throw ValidationException::withMessages(["ranges.{$index}.end_time" => 'Enter a valid time.']);
            }

            if (strtotime($end) <= strtotime($start)) {
                throw ValidationException::withMessages(["ranges.{$index}.end_time" => 'End time must be after the start time.']);
            }

            $result[] = [
                'day_of_week' => $day,
                'start_time' => $start.':00',
                'end_time' => $end.':00',
                'is_available' => true,
            ];
        }

        return $result;
    }

    /** Exactly `H:i` (24-hour, one or two digit hour) — the shape `start_time`/`end_time` must be before `:00` is appended for a `TIME` column. */
    protected function isValidTime(string $value): bool
    {
        return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    protected function authorizeSubject(): void
    {
        abort_unless(app(AvailabilityPolicy::class)->update($this->actor(), $this->subject), 403);
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.availability.grid', [
            'usingDefault' => $this->isUsingDefault(),
        ]);
    }
}
