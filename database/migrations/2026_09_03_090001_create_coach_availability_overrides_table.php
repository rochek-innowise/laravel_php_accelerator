<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-015's "partially blocked" half (Slice D). Tenant-owned (AD-001), unlike `Availability`
     * itself: `trainer_profile_id` uses `BelongsToTenant` on the model.
     *
     * `event_id` is a plain, nullable, unconstrained column — Epic-02's `events` table does not
     * exist yet, so there is nothing to add a foreign key to. This is the explicit, named seam
     * (Slice D plan, Gap 4): Epic-02's own migration adds the FK once the table exists.
     *
     * "The overriding trainer" is `trainer_profile_id -> trainerProfile -> user` (a TrainerProfile
     * has exactly one owning user), so no separate actor column is needed here.
     */
    public function up(): void
    {
        Schema::create('coach_availability_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coach_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['coach_profile_id', 'created_at']);
            $table->index('trainer_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_availability_overrides');
    }
};
