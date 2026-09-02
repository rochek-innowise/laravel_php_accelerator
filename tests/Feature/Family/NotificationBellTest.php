<?php

declare(strict_types=1);

namespace Tests\Feature\Family;

use App\Livewire\Family\NotificationBell;
use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Notifications\PurchaseApprovalRequested;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** AD-011: the bell reads the `database` channel Slice C introduces. */
final class NotificationBellTest extends TestCase
{
    #[Test]
    public function it_reflects_an_unread_database_notification_and_clears_it_on_read(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        $guardian->notify(new PurchaseApprovalRequested($approval));

        $notificationId = $guardian->notifications()->first()->id;

        $component = Livewire::actingAs($guardian)
            ->test(NotificationBell::class)
            ->assertSet('unreadCount', 1);

        $component->call('markAsRead', $notificationId);

        $component->assertSet('unreadCount', 0);
        $this->assertNotNull($guardian->notifications()->findOrFail($notificationId)->read_at);
    }

    #[Test]
    public function a_user_with_no_notifications_sees_a_zero_count(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->assertSet('unreadCount', 0);
    }
}
