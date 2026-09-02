<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PaymentType;
use App\Models\PurchaseApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-010 / NFR-009. Sent to every guardian the moment a child's purchase creates a `pending` row —
 * the request a guardian must act on within 48 hours before `ExpirePurchaseApprovalsJob` auto-
 * expires it. `via()` includes `database` (AD-011): the bell needs an in-app record too, not just
 * an email a guardian might not see in time.
 */
final class PurchaseApprovalRequested extends Notification implements ShouldQueue
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
            ->subject('Purchase approval needed for '.$child->name)
            ->greeting('Hello!')
            ->line($child->name.' has requested a purchase that needs your approval.')
            ->line('Amount: '.$this->amountLabel())
            ->line('This request expires in 48 hours if nobody responds.');
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

    private function amountLabel(): string
    {
        return $this->approval->payment_type === PaymentType::Usd
            ? '$'.number_format($this->approval->amount_cents / 100, 2)
            : $this->approval->amount_cents.' tokens';
    }
}
