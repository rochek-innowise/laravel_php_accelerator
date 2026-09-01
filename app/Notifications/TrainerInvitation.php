<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-006: the trainer sets their own password through a signed, expiring link — the invitation
 * never carries a temporary password.
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
        // TODO(coder): build a temporary signed URL to the password-setup route and compose the copy.
        throw new \RuntimeException('Not implemented');
    }
}
