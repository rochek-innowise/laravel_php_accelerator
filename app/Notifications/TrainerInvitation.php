<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * FR-006: the trainer sets their own password through the expiring link carried here, so the
 * invitation never contains a temporary password. Expiry is the password broker's
 * (config auth.passwords.users.expire — NFR-009).
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
        // The token is minted here rather than passed into the constructor: a queued notification
        // is serialized into the jobs table, and a plaintext reset token sitting there would
        // undo the point of storing only its hash in password_reset_tokens.
        $url = route('password.reset', [
            'token' => Password::broker()->createToken($notifiable),
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Your '.config('app.name').' trainer account')
            ->greeting('Welcome, '.$notifiable->first_name.'!')
            ->line('A trainer account has been created for you. Set your password to get started.')
            ->action('Set your password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If it has already expired, request a fresh one from the "Forgot your password?" link on the sign-in page.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
