<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ShareLink;
use App\Models\TrainerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-013. Unlike the trainer invitation, this one *must* carry its code: the link is the
 * invitation, single-use and 7-day (BR-009), and there is no account yet to send a reset to.
 *
 * The code is therefore in the queue payload. That is acceptable precisely because it is
 * single-use and short-lived — the two properties a password-reset token would not have had.
 */
final class CoachInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ShareLink $shareLink,
        public readonly TrainerProfile $trainerProfile,
        public readonly ?string $note = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->trainerProfile->business_name.' invited you to coach')
            ->greeting('Hello!')
            ->line($this->trainerProfile->business_name.' has invited you to join their coaching staff on '.config('app.name').'.');

        if (! empty($this->note)) {
            $message->line('"'.$this->note.'"');
        }

        return $message
            ->action('Accept the invitation', route('join', ['code' => $this->shareLink->code]))
            ->line('This invitation can be used once and expires on '.$this->shareLink->expires_at?->toFormattedDayDateString().'.')
            ->line('If you were not expecting it, you can ignore this email.');
    }
}
