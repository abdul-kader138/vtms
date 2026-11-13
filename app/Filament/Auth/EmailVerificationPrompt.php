<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\EmailVerification\EmailVerificationPrompt as BasePrompt;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * Overrides only the resend action's notification so it matches the one
 * sent at registration: App\Notifications\Auth\VerifyEmail, whose staff
 * link points at the auth-free `staff.verification.verify` route and so
 * works when opened on a different device. Filament's default here would
 * send its own VerifyEmail with a panel-login-gated URL.
 *
 * Registered via ->emailVerification(EmailVerificationPrompt::class) in
 * App\Providers\Filament\AdminPanelProvider.
 */
class EmailVerificationPrompt extends BasePrompt
{
    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }
}
