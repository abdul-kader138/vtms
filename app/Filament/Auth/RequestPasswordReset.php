<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;

/** Filament's request-reset page in the shared Metro sign-in frame. */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    protected static string $layout = 'filament.auth.metro-layout';
}
