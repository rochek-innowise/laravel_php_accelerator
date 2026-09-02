<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-008 photo storage for a child profile. `users.photo_path` only ever served a login; this
     * is the profile-level equivalent — additive, nullable, no backfill needed. Thumbnailing is
     * skipped for this slice (Decision 5 in the Slice C plan): full-size only.
     */
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('emergency_contact');
        });
    }

    public function down(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });
    }
};
