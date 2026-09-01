<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-006: the trainer sets their own password through the expiring reset link carried here, so
 * the invitation never contains a temporary password. Expiry is the password broker's
 * (config auth.passwords.users.expire, 60 minutes — NFR-009).
 */
final class TrainerInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Your '.config('app.name').' trainer account')
            ->greeting('Welcome, '.$notifiable->first_name.'!')
            ->line('A trainer account has been created for you. Set your password to get started.')
            ->action('Set your password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
