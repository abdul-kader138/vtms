<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

/**
 * Audience-aware verification notification, queued.
 *
 *  - Customers (API / SPA registrations, no panel role): the parent's
 *    default verificationUrl() builds the signed `verification.verify`
 *    route from routes/api.php, which marks the email verified without a
 *    session and redirects to the frontend app.
 *
 *  - Staff (isStaff() — anyone who registered through the Filament panel
 *    and got the `panel_user` role): a signed link to the auth-free
 *    `staff.verification.verify` route (routes/web.php →
 *    App\Http\Controllers\Auth\StaffVerifyEmailController) that verifies,
 *    logs them in and lands them in the panel. Filament's own verify route
 *    is behind Authenticate middleware and so 302s to /login when the
 *    email is opened on another device.
 */
class VerifyEmail extends BaseVerifyEmail implements ShouldQueue
{
    use Queueable;

    protected function verificationUrl($notifiable): string
    {
        if ($notifiable instanceof User && $notifiable->isStaff()) {
            return URL::temporarySignedRoute(
                'staff.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            );
        }

        return parent::verificationUrl($notifiable);
    }
}
