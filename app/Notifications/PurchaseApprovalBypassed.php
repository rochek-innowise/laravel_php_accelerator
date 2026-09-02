<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * BR-014. Sent when a token purchase bypassed approval because the child's guardian has not
 * required it (`token_spend_requires_approval === false`). Informational only — the row this
 * refers to is already `approved`, there is nothing to act on, but a guardian who never opted in
 * to the bypass should still see it happened.
 *
 * The `PurchaseApproval` constructor property is safe in the queued payload without an explicit
 * `use SerializesModels;` here — inherited from `Illuminate\Notifications\Notification`, which
 * already uses that trait. See `QueuePayloadPiiCheckTest`.
 */
final class PurchaseApprovalBypassed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PurchaseApproval $approval) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $child = $this->approval->playerProfile;

        return (new MailMessage)
            ->subject('Token purchase completed for '.$child->name)
            ->greeting('Hello!')
            ->line($child->name.' made a token purchase that did not require your approval.')
            ->line('Amount: '.$this->approval->amount_cents.' tokens.')
            ->line('You can require approval for this child again from their profile settings.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $child = $this->approval->playerProfile;

        return [
            'approval_id' => $this->approval->getKey(),
            'player_profile_id' => $child->getKey(),
            'child_name' => $child->name,
            'amount_cents' => $this->approval->amount_cents,
            'status' => $this->approval->status->value,
        ];
    }
}
