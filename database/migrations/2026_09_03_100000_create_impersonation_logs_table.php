<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Identity table (AD-001) — no BelongsToTenant, no global scope. Both foreign keys are
    // nullable + nullOnDelete, mirroring audit_logs's own choice; moot in practice since GDPR
    // erasure here never hard-deletes a row.
    public function up(): void
    {
        Schema::create('impersonation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // The history report's own query: every session for one target, newest first.
            $table->index(['target_user_id', 'started_at']);

            // CloseStaleImpersonationLogsJob's sweep: WHERE ended_at IS NULL AND started_at < ?
            $table->index(['ended_at', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_logs');
    }
};
