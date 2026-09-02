<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner-scoped, not tenant-owned (AD-001's third data class): reached only through the
     * `player_profiles` row that owns it, never queried from a trainer screen. No
     * `trainer_profile_id`, no `BelongsToTenant`.
     *
     * `approvable` is a nullable polymorphic reference Epic-02 will fill in once a purchasable
     * subject exists; nothing produces one yet, so the columns exist ahead of their only writer.
     *
     * The two indexes serve two different readers: `(status, expires_at)` is
     * `ExpirePurchaseApprovalsJob`'s sweep, `(player_profile_id, status)` is the approval queue and
     * a child's own read-only view of their own requests.
     */
    public function up(): void
    {
        Schema::create('purchase_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('approvable');
            $table->string('payment_type');
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');
            $table->text('parent_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['player_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_approvals');
    }
};
