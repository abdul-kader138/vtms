<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Auth\Concerns\LinksGoogleAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\GoogleLoginRequest;
use App\Http\Resources\CustomerResource;
use App\Services\DeviceLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;

/**
 * Mobile/native "Sign in with Google" — the app's own Google Sign-In SDK
 * gets an OAuth access token directly on-device (no browser redirect
 * involved), then hands it here to be verified and turned into a session,
 * the same shape POST /auth/login returns. See
 * App\Http\Controllers\Auth\CustomerGoogleAuthController for the browser
 * redirect version the React customer pages use instead — both end up at
 * the same App\Http\Controllers\Auth\Concerns\LinksGoogleAccount logic.
 *
 * Deliberately not behind the Turnstile captcha rule: Google having
 * already authenticated the user client-side is itself a strong signal —
 * the same reasoning App\Filament\Auth\Login already applies to skip a
 * 2FA challenge after a Google sign-in.
 */
class GoogleAuthController extends Controller
{
    use LinksGoogleAccount;

    #[OA\Post(
        path: '/api/v1/auth/google',
        summary: 'Sign in with Google (mobile/native)',
        description: "For a native app that already obtained a Google OAuth access token on-device via Google's own sign-in SDK. Establishes a session cookie AND returns a Sanctum bearer token, same as POST /auth/login. A browser-based frontend should use the /auth/google/redirect page instead, not this endpoint.",
        security: [['csrfToken' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['access_token'],
            properties: [
                new OA\Property(property: 'access_token', type: 'string', description: "The OAuth access token from Google's sign-in SDK (not the id_token)."),
                new OA\Property(property: 'remember', type: 'boolean', nullable: true),
                new OA\Property(property: 'device_id', type: 'string', nullable: true, description: 'Stable OS-level device identifier — see POST /auth/login.'),
                new OA\Property(property: 'device_name', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Logged in', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 422, description: 'The access token could not be verified with Google', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
        ],
    )]
    public function store(GoogleLoginRequest $request, DeviceLockService $deviceLock): JsonResponse
    {
        try {
            $googleUser = Socialite::driver('google')->userFromToken($request->validated('access_token'));
        } catch (\Throwable) {
            return response()->json(['message' => __('Your Google sign-in could not be verified. Please try again.')], 422);
        }

        if (blank($googleUser->getId()) || blank($googleUser->getEmail())) {
            return response()->json(['message' => __('Your Google sign-in could not be verified. Please try again.')], 422);
        }

        $user = $this->linkOrCreateGoogleUser($googleUser);

        $deviceLock->enforce($user, $request->input('device_id'), 'access_token');

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        $apiToken = $user->createToken($request->input('device_name') ?: 'mobile')->plainTextToken;

        // See AuthenticatedSessionController::store() for why this isn't
        // ->additional(['token' => ...])->response().
        return response()->json([...(new CustomerResource($user))->resolve(), 'token' => $apiToken]);
    }
}
