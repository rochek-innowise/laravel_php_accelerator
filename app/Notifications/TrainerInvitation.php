<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-006: no temporary password is ever mailed, and no reset token either. The invitation points
 * at the password-request form, so the trainer mints their own 60-minute token at the moment they
 * are actually ready to use it (NFR-009). This keeps the mail valid indefinitely — a token-bearing
 * link would have been dead on arrival for anyone opening the invitation the next morning — and
 * leaves nothing sensitive in the serialized queue payload.
 */
final class TrainerInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' trainer account')
            ->greeting('Welcome, '.$notifiable->first_name.'!')
            ->line('A trainer account has been created for you at '.$notifiable->email.'.')
            ->line('Set your password to get started — enter that address and we will email you a link.')
            ->action('Set your password', route('password.request'))
            ->line('This invitation does not expire, but the link you receive next is valid for '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
