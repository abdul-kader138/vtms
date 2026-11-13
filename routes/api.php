<?php

use App\Http\Controllers\Api\V1\Account\TwoFactorController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Named literally "verification.verify" at the top level, outside every
// prefix/name group below — Illuminate\Auth\Notifications\VerifyEmail::
// verificationUrl() looks up that exact global route name to build the
// signed link it emails out (see App\Notifications\Auth\VerifyEmail) and
// has no awareness of this app's "api.v1.*" route naming. The URI is still
// placed under /api/v1/auth to match the rest of this file.
//
// Deliberately NOT behind auth:sanctum: an unverified account can no
// longer establish a session at all (login is refused until the email is
// verified — see AuthenticatedSessionController::store()), so requiring a
// session here would be a deadlock. The 'signed' middleware is what
// authenticates the caller instead — the {id}/{hash} pair is validated in
// EmailVerificationController and the whole URL carries a signature that
// can't be forged or replayed past its expiry.
Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Versioned from day one — the customer-facing SPA (and any future mobile
// app) consumes /api/v1/*, while /admin/* (Filament) stays session-based
// and unversioned.
Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::prefix('auth')->name('auth.')->group(function () {
        // Native clients use this endpoint to discover whether they must
        // show the server-hosted Turnstile challenge before signing in.
        // Keeping it public lets the client fail clearly when an older
        // backend is still deployed instead of presenting a misleading
        // generic sign-in error.
        Route::get('/meta', fn () => response()->json([
            'captcha_required' => filled(config('services.turnstile.secret_key')),
            'site_key' => config('services.turnstile.site_key'),
        ]))->middleware('throttle:60,1')->name('meta');

        Route::get('/challenge-view', fn () => response()
            ->view('auth.mobile-challenge')
            ->header('Cache-Control', 'no-store'))
            ->middleware('throttle:60,1')
            ->name('challenge-view');

        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware('throttle:login')
            ->name('register');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:login')
            ->name('login');
        Route::post('/google', [GoogleAuthController::class, 'store'])
            ->middleware('throttle:login')
            ->name('google');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:login')
            ->name('password.email');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:login')
            ->name('password.store');

        // Second step of the challenge started by POST /auth/login when
        // the account has 2FA enabled — see
        // AuthenticatedSessionController::store() and
        // TwoFactorChallengeController.
        Route::post('/login/challenge', [TwoFactorChallengeController::class, 'store'])
            ->middleware('throttle:2fa-challenge')
            ->name('login.challenge');

        // auth:sanctum only, NOT 'verified' — these have to stay reachable
        // for an account that is authenticated but not yet verified (the
        // OAuth path can create one, and a session can outlive a later
        // email change). Everything else below requires a verified email.
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('verification.send');
        });
    });

    // 'verified' gates the entire customer surface: a logged-in account
    // whose email isn't verified gets a 403 ("Your email address is not
    // verified.") from every endpoint in this group. Login itself already
    // refuses an unverified account (see AuthenticatedSessionController),
    // so this is the defence-in-depth layer behind that.
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        })->name('user');

        Route::get('/account', [AccountController::class, 'show'])->name('account.show');
        Route::put('/account', [AccountController::class, 'update'])->name('account.update');
        Route::post('/account/avatar', [AccountController::class, 'updateAvatar'])->name('account.avatar.update');
        Route::delete('/account/avatar', [AccountController::class, 'destroyAvatar'])->name('account.avatar.destroy');
        // Each of these verifies a secret the account already has
        // (current password, or a TOTP code) — same brute-force shape as
        // login/2fa-challenge, so it gets the same class of limiter
        // rather than riding on the generic 'api' ceiling unthrottled.
        Route::put('/account/password', [AccountController::class, 'updatePassword'])
            ->middleware('throttle:account-security')
            ->name('account.password');
        Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
        Route::delete('/account', [AccountController::class, 'destroy'])
            ->middleware('throttle:account-security')
            ->name('account.destroy');

        Route::post('/account/two-factor/setup', [TwoFactorController::class, 'setup'])
            ->middleware('throttle:account-security')
            ->name('account.two-factor.setup');
        Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('throttle:account-security')
            ->name('account.two-factor.confirm');
        Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:account-security')
            ->name('account.two-factor.recovery-codes');
        Route::delete('/account/two-factor', [TwoFactorController::class, 'destroy'])
            ->middleware('throttle:account-security')
            ->name('account.two-factor.destroy');
    });
});
