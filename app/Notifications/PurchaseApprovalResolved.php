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
 * FR-010/FR-011. Sent to the child's own login — not the guardian — when their request is approved
 * or denied, so "the child sees the status transition" holds even though they never see the
 * approval queue itself. Never sent for a bypassed row (there was no decision to report) or an
 * expired one (`PurchaseApprovalExpired` covers that, to the guardians who let it lapse).
 */
final class PurchaseApprovalResolved extends Notification implements ShouldQueue
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
        $message = (new MailMessage)
            ->subject('Your purchase request was '.$this->approval->status->label())
            ->greeting('Hello!')
            ->line('Your purchase request for '.$this->amountLabel().' was '.$this->approval->status->label().'.');

        if (! empty($this->approval->parent_note)) {
            $message->line('Note from your guardian: '.$this->approval->parent_note);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'approval_id' => $this->approval->getKey(),
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
