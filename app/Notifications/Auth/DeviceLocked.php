<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by App\Services\DeviceLockService whenever a learner's account locks
 * to a (new) device — the first-ever login, or the first login after an
 * admin resets it. A security notice like PasswordChanged, not a marketing
 * update, so it ignores the lms_email_notifications preference.
 */
class DeviceLocked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?string $ipAddress,
        private readonly ?string $deviceName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Your account is now locked to this device'))
            ->greeting(__('Hi :name,', ['name' => $notifiable->first_name]))
            ->line(__('For your security, your account is now locked to the device you just signed in from.'))
            ->line(__('Signing in from any other device will be refused until an administrator resets your device.'));

        if ($this->deviceName) {
            $message->line(__('Device: :device', ['device' => $this->deviceName]));
        }

        if ($this->ipAddress) {
            $message->line(__('IP address: :ip', ['ip' => $this->ipAddress]));
        }

        return $message->line(__("If this wasn't you, contact support immediately."));
    }
}
