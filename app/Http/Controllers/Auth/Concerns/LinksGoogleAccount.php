<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Shared by the customer web OAuth callback (CustomerGoogleAuthController)
 * and the mobile API endpoint (Api\V1\Auth\GoogleAuthController) — both
 * arrive at the same Socialite user object via different routes (a
 * server-side redirect vs. an access token the app already obtained
 * on-device) but need identical find-or-create/link logic once they have
 * it.
 */
trait LinksGoogleAccount
{
    protected function linkOrCreateGoogleUser(SocialiteUser $googleUser): User
    {
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            // Google has already asserted this email address, so an account
            // that signs in this way is verified from here on — without
            // this, a pre-existing unverified account linking Google would
            // pass through login but then hit the 'verified' wall on every
            // /api/v1 call (see routes/api.php).
            $fill = [];

            if (! $user->google_id) {
                $fill['google_id'] = $googleUser->getId();
            }

            if (! $user->hasVerifiedEmail()) {
                $fill['email_verified_at'] = now();
            }

            if ($fill !== []) {
                $user->forceFill($fill)->save();
            }

            return $user;
        }

        return User::create([
            'first_name' => $googleUser->user['given_name'] ?? Str::before($googleUser->getName() ?? $googleUser->getEmail(), ' ') ?: 'Google',
            'last_name' => $googleUser->user['family_name'] ?? Str::after($googleUser->getName() ?? '', ' ') ?: 'User',
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'email_verified_at' => now(),
            'password' => null,
        ]);
    }
}
