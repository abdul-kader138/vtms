<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\CustomerResource;
use App\Models\User;
use App\Services\DeviceLockService;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The second step of AuthenticatedSessionController::store()'s challenge —
 * this is the only place a challenge token actually establishes a session.
 */
class TwoFactorChallengeController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/login/challenge',
        summary: 'Complete 2FA login challenge',
        description: 'Second step after `POST /auth/login` returns `two_factor_required: true`. Accepts either a current TOTP code or an unused recovery code.',
        security: [['csrfToken' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_token', 'code'],
            properties: [
                new OA\Property(property: 'challenge_token', type: 'string'),
                new OA\Property(property: 'code', type: 'string', example: '123456'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Logged in', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 422, description: 'Expired challenge or invalid code', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(TwoFactorChallengeRequest $request, TwoFactorAuthenticationService $service, DeviceLockService $deviceLock): JsonResponse
    {
        $cacheKey = "2fa-challenge:{$request->string('challenge_token')->toString()}";
        $payload = Cache::get($cacheKey);

        if (! $payload) {
            throw ValidationException::withMessages([
                'challenge_token' => [__('This challenge has expired. Please log in again.')],
            ]);
        }

        $user = User::find($payload['user_id']);
        $code = trim($request->string('code')->toString());

        $verified = $user && $service->verify($user->two_factor_secret, $code);

        if (! $verified && $user && $service->verifyRecoveryCode($user, $code)) {
            $service->consumeRecoveryCode($user, $code);
            $verified = true;
        }

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two-factor code was invalid.')],
            ]);
        }

        // Single-use regardless of outcome shape below — a code that just
        // worked shouldn't also be replayable against the same challenge.
        Cache::forget($cacheKey);

        $deviceLock->enforce($user, $payload['device_id'] ?? null, 'code');

        Auth::guard('web')->login($user, $payload['remember']);
        // Token-only clients (mobile, or a host not trusted for cookies)
        // have no session: they get the bearer token instead of a 500.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $apiToken = $user->createToken('mobile')->plainTextToken;

        // See AuthenticatedSessionController::store() for why this isn't
        // ->additional(['token' => ...])->response().
        return response()->json([...(new CustomerResource($user))->resolve(), 'token' => $apiToken]);
    }
}
