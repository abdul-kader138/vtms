<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Account\ConfirmTwoFactorRequest;
use App\Models\Setting;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Customer-facing equivalent of App\Filament\Auth\EditProfile's 2FA
 * actions — same two-step enable (generate, then confirm with a code
 * before it's trusted) since a secret that's never actually been proven to
 * work in the user's authenticator app is worse than no 2FA at all: it
 * would lock them out on their very next login.
 */
class TwoFactorController extends Controller
{
    /**
     * Generates a candidate secret and its QR code — not persisted yet.
     * The client sends the same secret back to confirm(), alongside a code
     * from the authenticator app that actually scanned it.
     */
    #[OA\Post(
        path: '/api/v1/account/two-factor/setup',
        summary: 'Start enabling 2FA: generate a candidate secret + QR code',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        responses: [
            new OA\Response(response: 200, description: 'Secret generated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'secret', type: 'string'),
                new OA\Property(property: 'qr_code_svg', type: 'string'),
            ])),
            new OA\Response(response: 422, description: '2FA already enabled, or disabled platform-wide', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
        ],
    )]
    public function setup(Request $request, TwoFactorAuthenticationService $service): JsonResponse
    {
        $this->ensureTwoFactorIsEnabledPlatformWide();

        if ($request->user()->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => __('Two-factor authentication is already enabled.')], 422);
        }

        $secret = $service->generateSecretKey();

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => $service->qrCodeSvg($request->user(), $secret),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/account/two-factor/confirm',
        summary: 'Confirm 2FA setup with a code from the authenticator app',
        description: 'Persists the secret from `setup` and returns one-time recovery codes — shown only once.',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['secret', 'code'],
            properties: [
                new OA\Property(property: 'secret', type: 'string'),
                new OA\Property(property: 'code', type: 'string', example: '123456'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Enabled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
            ])),
            new OA\Response(response: 422, description: 'Invalid code', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function confirm(ConfirmTwoFactorRequest $request, TwoFactorAuthenticationService $service): JsonResponse
    {
        $this->ensureTwoFactorIsEnabledPlatformWide();

        if (! $service->verify($request->string('secret')->toString(), $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two-factor code was invalid.')],
            ]);
        }

        $recoveryCodes = $service->generateRecoveryCodes();

        $request->user()->forceFill([
            'two_factor_secret' => $request->string('secret')->toString(),
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json([
            'message' => __('Two-factor authentication enabled.'),
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Password-gated like destroy() below — recovery codes are the
     * account-recovery path of last resort, so regenerating them (which
     * invalidates every existing one immediately) needs the same proof of
     * intent as turning 2FA off entirely, not just an active session.
     */
    #[OA\Post(
        path: '/api/v1/account/two-factor/recovery-codes',
        summary: 'Regenerate 2FA recovery codes',
        description: 'Invalidates every existing recovery code immediately. Password-gated, same as disabling 2FA entirely.',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password'],
            properties: [new OA\Property(property: 'password', type: 'string', format: 'password')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Regenerated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
            ])),
            new OA\Response(response: 422, description: 'Incorrect password, or 2FA not enabled', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function regenerateRecoveryCodes(Request $request, TwoFactorAuthenticationService $service): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('The password is incorrect.')],
            ]);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => __('Two-factor authentication is not enabled.')], 422);
        }

        $recoveryCodes = $service->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $recoveryCodes])->save();

        return response()->json([
            'message' => __('Recovery codes regenerated.'),
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/account/two-factor',
        summary: 'Disable 2FA',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password'],
            properties: [new OA\Property(property: 'password', type: 'string', format: 'password')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Disabled', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 422, description: 'Incorrect password', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('The password is incorrect.')],
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => __('Two-factor authentication disabled.')]);
    }

    private function ensureTwoFactorIsEnabledPlatformWide(): void
    {
        if (! Setting::get('two_factor_enabled', true)) {
            abort(422, __('Two-factor authentication is currently disabled for this application.'));
        }
    }
}
