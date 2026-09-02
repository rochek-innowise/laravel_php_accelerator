<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PlayerProfile;
use App\Models\ShareLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-011. A child login following an invitation link is refused association, but not silently:
 * every guardian gets this, carrying the link itself and a "Review Registration" CTA that reaches
 * the ordinary checklist flow Slice B already built — nothing new is needed on the parent's side.
 *
 * The `ShareLink` and `PlayerProfile` constructor properties are safe in the queued job payload
 * without an explicit `use SerializesModels;` here: this class extends
 * `Illuminate\Notifications\Notification`, which already `use`s that trait, so the child's name
 * and birth date never reach the `jobs` table — only a class+id `ModelIdentifier` does. See
 * `QueuePayloadPiiCheckTest` for the assertion against the real serialized payload.
 */
final class ChildShareLinkBlocked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ShareLink $shareLink,
        public readonly PlayerProfile $child,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->child->name.' tried to join an organisation')
            ->greeting('Hello!')
            ->line($this->child->name.' followed an invitation link, but a child account cannot join an organisation on its own.')
            ->line('If you want them to join, review and complete the registration yourself.')
            ->action('Review Registration', route('join', ['code' => $this->shareLink->code]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'share_link_code' => $this->shareLink->code,
            'player_profile_id' => $this->child->getKey(),
            'child_name' => $this->child->name,
        ];
    }
}
