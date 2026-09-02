<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * NFR-009 / BR-015. Sent to every guardian when `ExpirePurchaseApprovalsJob` auto-denies a request
 * nobody answered within 48 hours.
 */
final class PurchaseApprovalExpired extends Notification implements ShouldQueue
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
            ->subject('Purchase approval expired for '.$child->name)
            ->greeting('Hello!')
            ->line('A purchase request for '.$child->name.' went unanswered for 48 hours and has automatically expired.')
            ->line('No purchase was made.');
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
