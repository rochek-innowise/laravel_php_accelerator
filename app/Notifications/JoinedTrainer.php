<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TrainerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-007's confirmation email.
 *
 * The `TrainerProfile` is serialized into the queue payload by `SerializesModels`, which stores the
 * key and re-resolves the row when the job runs — so the business name is read fresh at send time,
 * not frozen at dispatch.
 */
final class JoinedTrainer extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TrainerProfile $trainerProfile) {}

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
            ->subject('You have joined '.$this->trainerProfile->business_name)
            ->greeting('Welcome, '.$notifiable->first_name.'!')
            ->line('You are now connected with '.$this->trainerProfile->business_name.'.')
            ->line('Their sessions and content are available under this organisation in your account.')
            ->action('Open your dashboard', route('dashboard'));
    }
}
