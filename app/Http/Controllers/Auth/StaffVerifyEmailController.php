<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Email verification for STAFF (Filament panel) accounts, linked from
 * App\Notifications\Auth\VerifyEmail. Deliberately has no auth-session
 * requirement — Filament's own verify route sits behind Authenticate
 * middleware, so its link 302s to /login whenever it is opened on a device
 * the user isn't signed in on (i.e. almost always: people open the email
 * on their phone).
 *
 * Security is unchanged: the route carries Laravel's `signed` middleware,
 * so the URL cannot be forged, altered or replayed past its expiry, and
 * the {hash} segment is sha1(user email) — the link dies the moment the
 * address changes.
 *
 * Customers use a separate, already-existing route (`verification.verify`
 * in routes/api.php) that redirects to the frontend SPA instead.
 */
class StaffVerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, __('This verification link is not valid for that account.'));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        // Sign them in on this device so they land in the panel rather than
        // on the login screen straight after verifying.
        if (! Auth::check()) {
            Auth::login($user);
        }

        return redirect()->intended(Filament::getUrl());
    }
}
