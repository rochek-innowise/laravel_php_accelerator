<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `owner_user_id` could express one guardian per child, so "mother and father" had no
 * representation at all. A child now has many guardians and a guardian many children.
 *
 * A self profile keeps no guardian row: the person is reached through `user_id`, and inventing a
 * row where someone guards themselves would make every query carry a special case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_guardians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('relationship')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['player_profile_id', 'guardian_user_id']);
            $table->index(['guardian_user_id', 'is_primary']);
        });

        // Existing owners become primary guardians, except where the owner is the profile's own
        // login — that relationship is already carried by `user_id`.
        DB::table('player_profiles')
            ->whereRaw('user_id IS NULL OR user_id <> owner_user_id')
            ->orderBy('id')
            ->chunkById(500, function ($profiles): void {
                $rows = [];

                foreach ($profiles as $profile) {
                    $rows[] = [
                        'player_profile_id' => $profile->id,
                        'guardian_user_id' => $profile->owner_user_id,
                        'relationship' => null,
                        'is_primary' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (! empty($rows)) {
                    DB::table('player_guardians')->insert($rows);
                }
            });

        // Order matters on MariaDB: the composite index backs the foreign key, so the constraint
        // has to go first or the index drop is refused.
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropForeign(['owner_user_id']);
            $table->dropIndex(['owner_user_id', 'is_child']);
            $table->dropColumn('owner_user_id');
            $table->index('is_child');
        });
    }

    public function down(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropIndex(['is_child']);
            // Nullable on the way back: a child with two guardians has no single owner to restore.
            $table->foreignId('owner_user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE player_profiles p
            SET owner_user_id = COALESCE(
                (
                    SELECT g.guardian_user_id FROM player_guardians g
                    WHERE g.player_profile_id = p.id
                    ORDER BY g.is_primary DESC, g.id ASC
                    LIMIT 1
                ),
                p.user_id
            )
        ');

        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->index(['owner_user_id', 'is_child']);
        });

        Schema::dropIfExists('player_guardians');
    }
};
