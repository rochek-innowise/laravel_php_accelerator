<?php

declare(strict_types=1);

namespace App\Livewire\Family;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * AD-011: database-backed, polled — Slice C's approval and ShareLink-blocking notifications are
 * the first callers of the `database` channel, so this is the first reader of it too.
 *
 * Embedded unconditionally in the shared layout (`TenancyQueryBudgetTest` pins the total query
 * count for a page load), so this stays to exactly **one** query: the latest unread notifications
 * are fetched once and the badge count is derived from that same collection in PHP, rather than a
 * second `count()` round trip. The one cost of that: a family with more than 10 unread
 * notifications sees the badge cap at 10 rather than the true total — acceptable at the volume
 * AD-011 itself describes ("a few times a day").
 */
final class NotificationBell extends Component
{
    private const MAX_SHOWN = 10;

    public int $unreadCount = 0;

    public function markAsRead(string $notificationId): void
    {
        $notification = $this->actor()->notifications()->whereKey($notificationId)->first();

        $notification?->markAsRead();
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        $unread = $this->actor()->unreadNotifications()->latest()->take(self::MAX_SHOWN)->get();

        $this->unreadCount = $unread->count();

        return view('livewire.family.notification-bell', [
            'unread' => $unread,
        ]);
    }
}
