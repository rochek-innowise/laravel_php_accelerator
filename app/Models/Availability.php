<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayOfWeek;
use Database\Factories\AvailabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// Identity data (AD-001's third data class), reached through the owning profile — never
// `BelongsToTenant`, never a global scope. `trainer_profile_id` NULL means "the default set,
// applies everywhere"; non-null means "an override that wholly replaces the default for that one
// trainer" (Slice D Decision 3). `available_for_type`/`available_for_id` and `trainer_profile_id`
// are not mass-assignable: they decide whose row this is and which set it belongs to, exactly the
// AD-016 shape every other ownership column in this codebase follows. Reads go through
// `AvailabilityResolver`, never a raw query built by hand elsewhere.
/**
 * @property int $available_for_id
 * @property string $available_for_type
 * @property int|null $trainer_profile_id
 * @property DayOfWeek $day_of_week
 * @property string|null $start_time
 * @property string|null $end_time
 * @property bool $is_available
 */
#[Fillable(['day_of_week', 'start_time', 'end_time', 'is_available'])]
class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'is_available' => 'boolean',
        ];
    }

    /** The `PlayerProfile` or `CoachProfile` this row belongs to. */
    /** @return MorphTo<Model, $this> */
    public function availableFor(): MorphTo
    {
        return $this->morphTo();
    }

    /** Null for a default row; the one trainer this override applies to otherwise. */
    /** @return BelongsTo<TrainerProfile, $this> */
    public function trainerProfile(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class);
    }
}
