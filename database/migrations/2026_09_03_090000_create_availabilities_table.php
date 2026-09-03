<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-014 / Slice D Decision 3. `trainer_profile_id` is nullable: NULL is the person's default
     * "Best Times", applying in every context; non-null is an override that wholly replaces the
     * default for that one trainer (never a row-level merge — the resolver deletes-and-replaces,
     * it never patches). A coach row is always non-null, since a coach has exactly one employer.
     *
     * `available_for_type`/`available_for_id` are written by hand, not via `morphs()`: the helper
     * would also add its own two-column index, which is redundant once the three-column composite
     * below exists (a leftmost prefix of it) — Decision 3 names exactly two indexes, not three.
     *
     * No `BelongsToTenant` / global scope on the model this table backs: identity data reached
     * through the owning profile, never queried unscoped directly. The trainer-side "who is free"
     * filter joins through the already-scoped `trainer_players` instead (see `AvailabilityResolver`).
     */
    public function up(): void
    {
        Schema::create('availabilities', function (Blueprint $table): void {
            $table->id();
            $table->string('available_for_type');
            $table->unsignedBigInteger('available_for_id');
            $table->foreignId('trainer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(
                ['available_for_type', 'available_for_id', 'trainer_profile_id'],
                'availabilities_available_for_trainer_index'
            );
            $table->index(['trainer_profile_id', 'day_of_week', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availabilities');
    }
};
