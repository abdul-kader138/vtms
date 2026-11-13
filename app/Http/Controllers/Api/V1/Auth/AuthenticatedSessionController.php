<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

class AuthenticatedSessionController extends Controller
{
    // How long a "code sent, waiting for the 6-digit entry" window stays
    // valid — matches the 5-minute window Filament's own admin login
    // challenge effectively gives via the session (see App\Filament\Auth\Login).
    private const CHALLENGE_TTL_MINUTES = 5;

    /**
     * Login for both the cookie-based Sanctum SPA and native mobile apps.
     * The 'web' guard session is established the same way for either (so a
     * customer session and an admin session stay indistinguishable at the
     * auth layer — only role/permission, via canAccessPanel(), decides what
     * either can reach), AND a Sanctum bearer token is issued in the same
     * response. A browser SPA relying on the cookie simply never uses the
     * `token` field; a mobile app stores it and sends
     * `Authorization: Bearer <token>` on every later request instead of
     * carrying cookies.
     *
     * Credentials are validated WITHOUT logging in whenever a 2FA challenge
     * is needed (same two-step shape as App\Filament\Auth\Login) — the
     * session/token is only established once the code verifies, via
     * TwoFactorChallengeController::store().
     *
     * `device_id` is optional and only meaningful for a customer account
     * (see App\Services\DeviceLockService) — a native app should send a
     * stable OS-level device identifier (NOT the MAC address; no client can
     * read that). Omitting it (the existing web SPA doesn't send one)
     * skips the lock entirely rather than failing the login.
     */
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Log in',
        description: "Establishes a session cookie AND returns a Sanctum bearer token — use whichever suits the client. If the account has 2FA enabled, returns a `challenge_token` instead — see `POST /auth/login/challenge`. `device_id` is optional; a customer account sending one locks to that device (see `POST /auth/logout` and the admin 'Reset device' action for how to clear it).",
        security: [['csrfToken' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'cf_turnstile_response', description: 'Cloudflare Turnstile token. Required when GET /api/v1/auth/meta reports captcha_required. Tokens are single-use.', type: 'string', nullable: true),
                new OA\Property(property: 'remember', type: 'boolean', nullable: true),
                new OA\Property(property: 'device_id', type: 'string', nullable: true, description: 'Stable OS-level device identifier from the native app (e.g. identifierForVendor on iOS, ANDROID_ID on Android). Omit for the web SPA.'),
                new OA\Property(property: 'device_name', type: 'string', nullable: true, description: 'Human-readable device label shown to admins, e.g. "iPhone 15 Pro".'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Logged in, or a 2FA challenge was issued', content: new OA\JsonContent(
                oneOf: [
                    new OA\Schema(ref: '#/components/schemas/Customer'),
                    new OA\Schema(properties: [
                        new OA\Property(property: 'two_factor_required', type: 'boolean', example: true),
                        new OA\Property(property: 'challenge_token', type: 'string'),
                    ], type: 'object'),
                ],
            )),
            new OA\Response(response: 403, description: 'Email not verified yet', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 422, description: 'Invalid credentials, or this account is locked to a different device', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(LoginRequest $request, DeviceLockService $deviceLock): JsonResponse
    {
        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($request->only('email', 'password'));

        if (! $user || ! $provider->validateCredentials($user, $request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => [__('These credentials do not match our records.')],
            ]);
        }

        // An unverified account cannot sign in. Credentials were correct,
        // so quietly send a fresh verification link (the one from
        // registration may have expired) and tell the client to route the
        // user to a "check your email" screen. No session is established
        // and 2FA is not even attempted.
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => __("Your email address isn't verified yet. We've sent you a new verification link — check your inbox."),
                'code' => 'email_unverified',
            ], 403);
        }

        if ($this->requiresTwoFactor($user)) {
            $token = Str::random(40);

            Cache::put("2fa-challenge:{$token}", [
                'user_id' => $user->id,
                'remember' => (bool) $request->boolean('remember'),
                'device_id' => $request->input('device_id'),
            ], now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $token,
            ]);
        }

        $deviceLock->enforce($user, $request->input('device_id'), 'email');

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        // Token-only clients (mobile, or a host not trusted for cookies)
        // have no session: they get the bearer token instead of a 500.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $apiToken = $user->createToken($request->input('device_name') ?: 'mobile')->plainTextToken;

        // Not ->additional(['token' => ...])->response() — Laravel forces
        // the "data" wrapper back on whenever additional() is used, even
        // though JsonResource::withoutWrapping() is set globally (see
        // AppServiceProvider::boot()). Merging into the plain resolve()d
        // array keeps the existing flat {id, email, ...} response shape
        // every client (and test) already depends on.
        return response()->json([...(new CustomerResource($user))->resolve(), 'token' => $apiToken]);
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Log out',
        description: 'Ends the web session (if any) and revokes the bearer token this request was authenticated with (if any) — a mobile app should discard its stored token after calling this.',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Auth'],
        responses: [new OA\Response(response: 200, description: 'Logged out', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse'))],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('Logged out.')]);
    }

    private function requiresTwoFactor(User $user): bool
    {
        return (bool) Setting::get('two_factor_enabled', true) && $user->hasEnabledTwoFactorAuthentication();
    }
}
