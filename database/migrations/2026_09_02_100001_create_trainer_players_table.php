<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The association row is what makes a person reachable inside an organisation (AD-001) — a
     * trainer's roster is a query over this table joined to profiles, never PlayerProfile::query().
     *
     * `deleted_at` is part of the unique index on purpose: MariaDB does not collide on NULLs, so a
     * live association is unique while any number of removed ones may sit behind it. That is what
     * lets FR-009 preserve history and still allow a re-association later.
     */
    public function up(): void
    {
        Schema::create('trainer_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('share_link_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('connected_at');
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(
                ['trainer_profile_id', 'player_profile_id', 'deleted_at'],
                'trainer_players_live_association_unique'
            );
            $table->index(['player_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_players');
    }
};
