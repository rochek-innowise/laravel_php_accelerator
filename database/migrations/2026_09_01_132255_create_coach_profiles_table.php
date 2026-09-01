<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // BR-006 (one active trainer per coach) is enforced by the database in Slice B via a
    // generated column; MariaDB has no partial unique index. TODO(coder): add with coach invites.
    public function up(): void
    {
        Schema::create('coach_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('invited');
            $table->text('bio')->nullable();
            $table->text('credentials')->nullable();
            $table->text('certifications')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->index(['trainer_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_profiles');
    }
};
