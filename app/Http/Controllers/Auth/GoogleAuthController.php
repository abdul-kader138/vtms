<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * "Sign in with Google" for the admin panel — separate from the normal
 * credentials/2FA flow in App\Filament\Auth\Login, since OAuth never goes
 * through Filament's Login page at all (Google redirects straight back
 * here). Google having already verified the email address is treated as
 * equivalent to our own email verification step, and stands in for the
 * TOTP challenge too — a Google account login is itself a strong second
 * factor, so there's nothing useful a local 2FA prompt would add on top.
 */
class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->failed('That Google sign-in link expired. Please try again.');
        } catch (\Throwable) {
            return $this->failed('Google sign-in failed. Please try again.');
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        // This is the staff-only admin panel login — unlike the customer
        // Google flow (CustomerGoogleAuthController), it must never
        // provision a new account on the fly. Staff accounts are created
        // deliberately via UserResource/ShieldSeeder.
        if (! $user || ! $user->isStaff()) {
            return $this->failed('No staff account found for that Google account.');
        }

        if (! $user->google_id) {
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        Filament::auth()->login($user);
        session()->regenerate();

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }

    private function failed(string $message): RedirectResponse
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->send();

        return redirect()->route('filament.admin.auth.login');
    }
}
