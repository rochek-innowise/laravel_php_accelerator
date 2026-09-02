<?php

declare(strict_types=1);

namespace App\Actions\ShareLink;

use App\Actions\Family\AssociatePlayersWithTrainer;
use App\Enums\ShareLinkType;
use App\Exceptions\ShareLinkNotRedeemableException;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\User;
use App\Notifications\JoinedTrainer;
use App\Services\AuditLogger;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FR-007. The correctness centre of Slice B.
 *
 * Redemption holds `lockForUpdate` on the link row, so the uses_count increment and the single-use
 * check hold under NFR-004's concurrent registrations: without it, two simultaneous redemptions of
 * a one-shot coach link both succeed. The welcome notification is dispatched *after commit*
 * (AD-006/AD-007) — sending inside the transaction would hold a row lock across an SMTP round trip.
 */
final class RedeemShareLink
{
    public function __construct(
        protected AssociatePlayersWithTrainer $associate,
        protected AuditLogger $auditLogger,
        protected TrainerContext $context,
    ) {}

    /**
     * Look a link up for display. Tenant-blind by necessity: a guest following an invitation has no
     * organisation yet, which is the whole point of the link.
     */
    public function find(string $code): ?ShareLink
    {
        return $this->context->runAsSystem(
            fn (): ?ShareLink => ShareLink::query()->where('code', $code)->first()
        );
    }

    /**
     * @param  list<int>  $profileIds  Family members the account asked to enrol.
     * @return Collection<int, TrainerPlayer>
     *
     * @throws ShareLinkNotRedeemableException
     */
    public function forPlayer(string $code, User $user, array $profileIds): Collection
    {
        [$link, $associations] = DB::transaction(function () use ($code, $user, $profileIds): array {
            $link = $this->lockRedeemable($code, ShareLinkType::Player);
            $trainer = $link->trainerProfile()->firstOrFail();

            $associations = $this->associate->handle($trainer, $user, $profileIds, $link);

            // A redemption that enrolled nobody — every submitted id was outside the family — is
            // not a use of the link, so it must not consume a single-use one.
            if ($associations->isNotEmpty()) {
                $this->consume($link);
            }

            $this->auditLogger->log('share-link.redeemed', $link, [
                'user_id' => $user->getKey(),
                'player_profile_ids' => $associations->pluck('player_profile_id')->all(),
            ]);

            return [$link, $associations];
        });

        if ($associations->isNotEmpty()) {
            DB::afterCommit(fn () => $user->notify(new JoinedTrainer($link->trainerProfile()->firstOrFail())));
        }

        return $associations;
    }

    /**
     * @throws ShareLinkNotRedeemableException
     */
    public function lockRedeemable(string $code, ?ShareLinkType $expected = null): ShareLink
    {
        $link = $this->context->runAsSystem(
            fn (): ?ShareLink => ShareLink::query()->where('code', $code)->lockForUpdate()->first()
        );

        // The type is asserted here rather than left to the caller's branch: a player link reaching
        // the coach path (or the reverse) is a programming error, and one message for every
        // rejection keeps a code namespace unprobeable.
        if ($link === null || ! $link->isRedeemable() || ($expected !== null && $link->type !== $expected)) {
            throw new ShareLinkNotRedeemableException($link);
        }

        return $link;
    }

    /** Single-use links deactivate the moment they are spent, so an exhausted code is inert. */
    public function consume(ShareLink $link): void
    {
        $link->forceFill(['uses_count' => $link->uses_count + 1]);

        if ($link->isExhausted()) {
            $link->forceFill(['is_active' => false]);
        }

        $link->save();
    }
}
