<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ResendVerificationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = trim($data['email']);
        $key = 'resend-verification:'.hash('sha256', strtolower($email));

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return redirect()->route('customer.verification-notice')
                ->withInput(['email' => $email])
                ->withErrors(['email' => __('Please wait a minute before requesting another verification email.')]);
        }

        RateLimiter::hit($key, 60);
        $user = User::where('email', $email)->first();
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return redirect()->route('customer.verification-notice')
            ->withInput(['email' => $email])
            ->with('status', __('If this address belongs to an unverified account, a new verification link has been requested. Check your inbox and spam folder.'));
    }
}
