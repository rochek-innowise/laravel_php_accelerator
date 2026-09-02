<?php

declare(strict_types=1);

namespace App\Actions\ShareLink;

use App\Enums\ShareLinkType;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Support\Facades\DB;

/**
 * FR-007 / BR-008: one static, unlimited-use, never-expiring player link per organisation.
 *
 * Regenerating deactivates the previous code rather than leaving both live. BR-008 makes a link
 * unlimited in *uses*, not immortal as a *code* — a trainer who regenerates after posting a link
 * publicly expects the old one to stop working, and two live codes would make revocation a lie.
 */
final class GeneratePlayerShareLink
{
    public function __construct(protected AuditLogger $auditLogger, protected TrainerContext $context) {}

    public function handle(TrainerProfile $trainer, User $actor): ShareLink
    {
        return DB::transaction(function () use ($trainer, $actor): ShareLink {
            // Locked, so two concurrent regenerations cannot both pass the deactivate step and
            // leave two live codes — which would make "the previous one no longer works" false.
            $this->context->runFor($trainer, function () use ($trainer): void {
                $live = $trainer->shareLinks()
                    ->where('type', ShareLinkType::Player)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get();

                foreach ($live as $link) {
                    $link->forceFill(['is_active' => false])->save();
                }
            });

            $link = new ShareLink(['type' => ShareLinkType::Player]);

            // Minted, never supplied: the code is the credential this link carries.
            $link->forceFill([
                'code' => ShareLink::mintCode(),
                'trainer_profile_id' => $trainer->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'max_uses' => ShareLinkType::Player->maxUses(),
                'expires_at' => null,
                // Set explicitly rather than left to the column default: a model that relies on the
                // default comes back from save() with the attribute unhydrated, so the link the
                // caller holds reports itself inactive until something reloads it.
                'is_active' => true,
                'uses_count' => 0,
            ])->save();

            $this->auditLogger->log('share-link.generated', $link, [
                'type' => ShareLinkType::Player->value,
                'trainer_profile_id' => $trainer->getKey(),
            ]);

            return $link;
        });
    }

    /** The link a trainer currently hands out, or null when they have not minted one yet. */
    public function existing(TrainerProfile $trainer): ?ShareLink
    {
        return $this->context->runFor(
            $trainer,
            fn (): ?ShareLink => $trainer->shareLinks()
                ->where('type', ShareLinkType::Player)
                ->where('is_active', true)
                ->latest('id')
                ->first()
        );
    }
}
