<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-018 / Slice D Decision 7 (brainstorming). The minimal, purpose-justified record GDPR
     * Art. 17(3) permits — no payload column, no clear-text email. `email_hash` is a salted hash
     * (config `gdpr.email_hash_salt`, never hardcoded) so the "was this address ever erased / is
     * this person re-registering" query works without retaining the address itself.
     *
     * Both user foreign keys are nullable + nullOnDelete, mirroring `audit_logs`/
     * `impersonation_logs`'s own choice — moot in practice since GDPR erasure here never
     * hard-deletes a `users` row, only anonymizes it.
     *
     * No `SoftDeletes` on the model this table backs, despite the `deleted_at`-shaped column
     * name: it records when the *original user* was erased, not when this log row itself was
     * soft-deleted. Mixing the trait in would silently hide every row from every query — see the
     * model's own comment.
     */
    public function up(): void
    {
        Schema::create('user_deletion_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email_hash', 64);
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('deleted_at');
            $table->timestamps();

            // "Was this address ever erased / is this person re-registering" lookup.
            $table->index('email_hash');

            // Unused until Gap 10's deferred purge job exists; cheap to add now.
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_deletion_logs');
    }
};
