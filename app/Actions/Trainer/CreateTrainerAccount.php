<?php

declare(strict_types=1);

namespace App\Actions\Trainer;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Notifications\TrainerInvitation;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * FR-006: only a Super Admin creates trainers (BR-003). The account is created with an unusable
 * random password; the trainer sets their own through the expiring reset link in the invitation,
 * so no temporary password is ever mailed.
 */
final class CreateTrainerAccount
{
    public function __construct(protected AuditLogger $auditLogger) {}

    /**
     * @param  array{business_name: string, first_name: string, last_name: string, email: string, phone?: string|null}  $data
     */
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = new User([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make(Str::random(64)),
            ]);

            // Privilege columns are not mass-assignable; this action decides them.
            $user->forceFill([
                'role' => Role::Trainer,
                'status' => UserStatus::Active,
            ])->save();

            $user->trainerProfile()->create([
                'business_name' => $data['business_name'],
                'slug' => $this->uniqueSlug($data['business_name']),
            ]);

            $this->auditLogger->log('trainer.created', $user, [
                'business_name' => $data['business_name'],
            ]);

            return $user;
        });

        // After commit (AD-007): a rolled-back transaction must never leave a sent invitation.
        DB::afterCommit(function () use ($user): void {
            $user->notify(new TrainerInvitation);
        });

        return $user;
    }

    protected function uniqueSlug(string $businessName): string
    {
        $base = Str::slug($businessName);
        $slug = $base;
        $suffix = 1;

        while (TrainerProfile::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
