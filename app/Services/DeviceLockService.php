<?php

namespace App\Services;

use App\Jobs\CheckLoginLocationAnomaly;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\Auth\DeviceLocked;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hard device lock for learner accounts: the first device a learner logs
 * in from is remembered (by a client-supplied identifier, not a real MAC
 * address — no client, web or native, can ever read that), and a login
 * attempt from any other device is refused outright rather than allowed
 * after the softer cooldown App\Http\Middleware\EnsureSingleDevice already
 * enforces for concurrent-session kickout. Only an admin resetting the
 * device (see App\Filament\Resources\UserResource) clears it.
 *
 * Staff/admin accounts are exempt — see User::isStaff() — so an admin can
 * never lock themselves out with nobody left able to reset them.
 *
 * Two callers, two kinds of identifier, same enforcement:
 *  - App\Filament\Auth\Login (server-rendered admin panel) passes a
 *    browser fingerprint computed client-side — see
 *    resources/views/filament/forms/components/device-fingerprint.blade.php.
 *  - App\Http\Controllers\Api\V1\Auth\{AuthenticatedSessionController,
 *    TwoFactorChallengeController, GoogleAuthController} (the mobile app)
 *    pass an OS-level device ID the native app reads itself — more
 *    reliable than a browser fingerprint since it doesn't change if the
 *    user later installs a different browser, because there isn't one.
 *  The API callers' `device_id` field is optional: a request that omits
 *  it (the existing cookie-based web SPA, which doesn't send one) is
 *  treated exactly like the "no identifier" branch below, so adding this
 *  to the API never breaks that existing, unrelated client.
 */
class DeviceLockService
{
    /**
     * @param  string  $messageKey  The validation-error key a thrown
     *                              ValidationException's message is filed
     *                              under. App\Filament\Auth\Login's Livewire
     *                              form needs 'data.email'; the plain JSON
     *                              API controllers pass 'email' so the error
     *                              lands in the normal {"errors":{"email":
     *                              [...]}} shape instead of a Livewire path.
     */
    public function enforce(User $user, ?string $fingerprint, string $messageKey = 'data.email'): void
    {
        if (! Setting::get('customer_device_lock_enabled', true)) {
            return;
        }

        if ($user->isStaff()) {
            return;
        }

        $fingerprint = trim((string) $fingerprint);
        $record = UserSession::where('user_id', $user->id)->first();
        $locked = $record && filled($record->device_fingerprint);

        if ($fingerprint === '') {
            // No device identifier reached us (JavaScript disabled on the
            // web login, or an API caller that doesn't send device_id —
            // e.g. the existing cookie-based web SPA). Fail closed only
            // once a device is already locked — an unlocked account's
            // first-ever login still has to get in somehow, and refusing
            // it here would strand it with no recovery path other than an
            // admin reset it never earned.
            if ($locked) {
                $this->logBlocked($user, 'no_device_identifier');

                throw ValidationException::withMessages([
                    $messageKey => __('We couldn\'t verify this device. Please try again or contact support.'),
                ]);
            }

            return;
        }

        if ($locked && ! hash_equals($record->device_fingerprint, $fingerprint)) {
            $this->logBlocked($user, 'device_mismatch');

            throw ValidationException::withMessages([
                $messageKey => __('This account is locked to another device. Ask an administrator to reset your device before signing in here.'),
            ]);
        }

        // True before the write below — this is the first-ever lock, or the
        // first login since an admin reset it — so the real account owner
        // gets told their account just claimed a device, whether that was
        // them or not.
        $isNewLock = ! $locked;

        $this->rememberDevice($user, $fingerprint);

        if ($isNewLock) {
            $user->notify(new DeviceLocked(request()->ip(), Str::limit((string) request()->userAgent(), 80)));
        }

        // Off the request path on purpose (ShouldQueue) — a slow or failed
        // geolocation lookup must never delay or break a login. Dispatched
        // on every successful pass, not just new locks: the same device
        // logging in from a very different country than last time is the
        // signal worth seeing, and that can happen on an already-locked
        // device too (e.g. the account was handed off along with the
        // browser profile).
        CheckLoginLocationAnomaly::dispatch($user->id, (string) request()->ip());
    }

    /**
     * Writes the user_sessions row AND puts the matching token straight
     * into the session, so the very next request's pass through
     * EnsureSingleDevice lands on its "token present, matches" branch
     * instead of its own "no token yet" takeover/cooldown logic — that
     * logic stays exactly as-is for staff, who never reach this method.
     */
    private function rememberDevice(User $user, string $fingerprint): void
    {
        $token = Str::random(64);

        UserSession::updateOrCreate(
            ['user_id' => $user->id],
            [
                'session_token' => $token,
                'device_fingerprint' => $fingerprint,
                'device_name' => Str::limit((string) request()->userAgent(), 80),
                'ip_address' => request()->ip(),
                'last_seen_at' => now(),
            ]
        );

        // The plain session() helper, not $request->session() — matches
        // every other session touch in App\Filament\Auth\Login, and unlike
        // the Request facade's ->session() it doesn't require the
        // StartSession middleware to have run on this exact Request
        // instance (e.g. under Livewire::test()).
        session()->put('device_session_token', $token);
    }

    /**
     * Same activity('auth') log App\Listeners\LogAuthenticationActivity
     * writes to for every login/logout/failed attempt — a blocked device
     * mismatch lands in the same audit trail admins already have, instead
     * of a second place to go looking.
     */
    private function logBlocked(User $user, string $reason): void
    {
        activity('auth')
            ->causedBy($user)
            ->withProperties([
                'ip' => request()->ip(),
                'device_name' => Str::limit((string) request()->userAgent(), 80),
                'reason' => $reason,
            ])
            ->log('Blocked login — device lock');
    }
}
