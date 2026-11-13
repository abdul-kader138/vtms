<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * The link in the verification email is a plain browser navigation
     * (see App\Notifications\Auth\VerifyEmail), not an XHR call from the
     * SPA, so this ends in a redirect back to the frontend rather than a
     * JSON response.
     *
     * It resolves the user from the {id} route parameter and checks {hash}
     * against that user's email — it does NOT require an authenticated
     * session, because an unverified account has none (login is refused
     * until the email is verified). The 'signed' middleware on the route
     * (see routes/api.php) guarantees the URL — id, hash and expiry
     * included — was minted by us and hasn't been altered or replayed.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403, __('This verification link is not valid for that account.'));
        }

        $alreadyVerified = $user->hasVerifiedEmail();

        if (! $alreadyVerified) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        // Keep the verification result inside this app. FRONTEND_URL may
        // point to a separate SPA/dev server and can otherwise send
        // customers to its login screen instead of this success page.
        return redirect()->route('customer.email-verified', [
            'status' => $alreadyVerified ? 'already-verified' : 'verified',
        ]);
    }
}
