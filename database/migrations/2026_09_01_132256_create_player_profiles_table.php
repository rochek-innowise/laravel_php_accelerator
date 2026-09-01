<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A trainable person, not an account: owned by a user, optionally backed by its own login.
    // Never tenant-scoped — reachability inside a tenant is the trainer_players row (AD-001).
    public function up(): void
    {
        Schema::create('player_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->string('skill_level')->nullable();
            $table->string('school')->nullable();
            $table->string('jersey_number')->nullable();
            $table->boolean('is_child')->default(false);
            $table->text('emergency_contact')->nullable();
            $table->boolean('token_spend_requires_approval')->default(true);
            $table->timestamps();

            $table->index(['owner_user_id', 'is_child']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_profiles');
    }
};
