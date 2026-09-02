<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\ShareLink;
use App\Models\User;
use App\Notifications\ChildShareLinkBlocked;
use App\Notifications\PurchaseApprovalBypassed;
use App\Notifications\PurchaseApprovalExpired;
use App\Notifications\PurchaseApprovalRequested;
use App\Notifications\PurchaseApprovalResolved;
use Illuminate\Notifications\Notification as NotificationBase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice C review finding: these five notifications hold Eloquent models in constructor properties
 * and use only `Queueable`, which looked like a gap next to `JoinedTrainer`'s explicit
 * `SerializesModels` docblock. It is not: every notification already extends
 * `Illuminate\Notifications\Notification`, and that base class itself `use`s `SerializesModels`
 * (see vendor/laravel/framework/src/Illuminate/Notifications/Notification.php) — the trait is
 * inherited, not opted into per-class, which is exactly the convention `JoinedTrainer` documents.
 *
 * This test proves the payload actually written to the `jobs` table (the `database` queue driver
 * persists it, per AD-017) carries a `ModelIdentifier` rather than the model's raw attributes, and
 * that the queued job still completes — i.e. the model re-resolves correctly on unserialize — for
 * every one of the five notifications named in the finding.
 */
final class QueuePayloadPiiCheckTest extends TestCase
{
    #[Test]
    public function child_share_link_blocked_payload_carries_no_raw_child_pii(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create([
            'name' => 'Zephyr Nakamura',
            'birth_date' => '2015-06-15',
        ]);
        $link = ShareLink::factory()->create();

        $this->assertPayloadIsSafeAndReResolves(
            fn () => $guardian->notify(new ChildShareLinkBlocked($link, $child)),
            piiNeedles: ['Zephyr Nakamura'],
        );
    }

    #[Test]
    public function purchase_approval_requested_payload_carries_no_raw_child_pii(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['name' => 'Zephyr Nakamura']);
        $approval = PurchaseApproval::factory()->for($child, 'playerProfile')->create();

        $this->assertPayloadIsSafeAndReResolves(
            fn () => $guardian->notify(new PurchaseApprovalRequested($approval)),
            piiNeedles: ['Zephyr Nakamura'],
        );
    }

    #[Test]
    public function purchase_approval_resolved_payload_carries_no_raw_child_pii(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create([
            'name' => 'Zephyr Nakamura',
            'user_id' => $childLogin->id,
        ]);
        $approval = PurchaseApproval::factory()->approved()->for($child, 'playerProfile')->create();

        $this->assertPayloadIsSafeAndReResolves(
            fn () => $childLogin->notify(new PurchaseApprovalResolved($approval)),
            piiNeedles: ['Zephyr Nakamura'],
        );
    }

    #[Test]
    public function purchase_approval_expired_payload_carries_no_raw_child_pii(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['name' => 'Zephyr Nakamura']);
        $approval = PurchaseApproval::factory()->denied()->for($child, 'playerProfile')->create();

        $this->assertPayloadIsSafeAndReResolves(
            fn () => $guardian->notify(new PurchaseApprovalExpired($approval)),
            piiNeedles: ['Zephyr Nakamura'],
        );
    }

    #[Test]
    public function purchase_approval_bypassed_payload_carries_no_raw_child_pii(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['name' => 'Zephyr Nakamura']);
        $approval = PurchaseApproval::factory()->approved()->token()->for($child, 'playerProfile')->create();

        $this->assertPayloadIsSafeAndReResolves(
            fn () => $guardian->notify(new PurchaseApprovalBypassed($approval)),
            piiNeedles: ['Zephyr Nakamura'],
        );
    }

    #[Test]
    public function the_base_notification_class_is_the_source_of_the_serializes_models_behaviour(): void
    {
        // Documents *why* the five classes above need no `use SerializesModels;` of their own:
        // it is already present on every Notification via the framework base class.
        $this->assertContains(
            'Illuminate\Queue\SerializesModels',
            class_uses_recursive(NotificationBase::class),
        );
    }

    /** @param list<string> $piiNeedles */
    private function assertPayloadIsSafeAndReResolves(callable $dispatch, array $piiNeedles): void
    {
        config(['queue.default' => 'database']);

        $dispatch();

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row, 'Expected the notification to be queued onto the database driver.');

        foreach ($piiNeedles as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $row->payload,
                'Raw PII leaked into the queued job payload instead of a ModelIdentifier.',
            );
        }

        $this->assertStringContainsString('ModelIdentifier', $row->payload);

        // Re-resolution: draining the real database queue proves the ModelIdentifier round-trips
        // back into a usable Eloquent model, not just that it was written that way. `notify()`
        // queues one job per channel, so drain until empty rather than stopping after one.
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
        ]);

        $this->assertSame(0, DB::table('jobs')->count(), 'Queued job did not drain.');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Queued job failed instead of re-resolving the model.');
    }
}
