<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\Component;
use Filament\Pages\Auth\PasswordReset\ResetPassword as BaseResetPassword;

/**
 * Strength itself (min length, mixed case, numbers) is enforced by the
 * Password::default() rule the base component already applies — see
 * AppServiceProvider::boot(). This just surfaces the requirement here too,
 * instead of letting the user find out from a rejected form. See
 * App\Filament\Auth\Login/Register/EditProfile for the same panel's other
 * auth-page customisations.
 */
class ResetPassword extends BaseResetPassword
{
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->helperText(__('At least 8 characters, with uppercase, lowercase, and a number.'));
    }
}
