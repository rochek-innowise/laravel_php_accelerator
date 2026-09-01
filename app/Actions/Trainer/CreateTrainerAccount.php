<?php

declare(strict_types=1);

namespace App\Actions\Trainer;

use App\Models\User;
use App\Services\AuditLogger;

/**
 * FR-006: Super Admin creates a trainer. One transaction creating the User and its
 * TrainerProfile; the invitation notification is dispatched after commit (AD-007), never inside.
 */
final class CreateTrainerAccount
{
    public function __construct(protected AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): User
    {
        // TODO(coder): DB::transaction() creating the user (role trainer, status active) and its
        // trainer profile; write the audit entry; dispatch TrainerInvitation via DB::afterCommit().
        throw new \RuntimeException('Not implemented');
    }
}
