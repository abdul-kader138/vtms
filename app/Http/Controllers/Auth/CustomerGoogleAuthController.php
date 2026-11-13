<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\LinksGoogleAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Customer-side "Sign in with Google" — separate route/callback from
 * App\Http\Controllers\Auth\GoogleAuthController (the admin panel's own),
 * kept distinct so a customer's Google account never gets the admin
 * flow's find-or-create behavior (which assigns 'panel_user'). Landing in
 * Filament itself is fine now — see User::canAccessPanel(), which no
 * longer gates on having a role — this just shouldn't be the path that
 * hands one out. Shares the same Google OAuth client credentials
 * (config('services.google.*')) but registers its own redirect URI via
 * Socialite's redirectUrl(), since a single Google client can be
 * authorized for multiple redirect URIs.
 *
 * This is the browser redirect flow, for the React customer pages. A
 * native mobile app can't follow a server-side redirect the same way —
 * see Api\V1\Auth\GoogleAuthController for the token-based equivalent it
 * uses instead. Both share find-or-create logic via LinksGoogleAccount.
 */
class CustomerGoogleAuthController extends Controller
{
    use LinksGoogleAccount;

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->redirectUrl(route('customer.auth.google.callback'))
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl(route('customer.auth.google.callback'))
                ->user();
        } catch (InvalidStateException) {
            return $this->failed('expired');
        } catch (\Throwable) {
            return $this->failed('failed');
        }

        $user = $this->linkOrCreateGoogleUser($googleUser);
        $user->markAsCustomer();

        Auth::guard('web')->login($user);
        request()->session()->regenerate();

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/auth/callback?status=success');
    }

    private function failed(string $reason): RedirectResponse
    {
        return redirect()->route('filament.admin.auth.login', ['error' => $reason]);
    }
}
