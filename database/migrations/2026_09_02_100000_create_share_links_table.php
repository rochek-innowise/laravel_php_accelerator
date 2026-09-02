<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-007 / FR-013. A player link is permanent and unlimited-use (BR-008), so its code is a
     * standing route into a roster: `code` is minted from `random_bytes`, never a sequence, and
     * the unique index is what makes a collision a failed insert rather than a hijacked link.
     */
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type');
            $table->foreignId('trainer_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_email')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['trainer_profile_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
