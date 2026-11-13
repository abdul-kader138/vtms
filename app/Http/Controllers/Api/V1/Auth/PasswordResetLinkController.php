<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PasswordResetLinkController extends Controller
{
    /**
     * Always responds the same way regardless of whether the email matched
     * an account — Password::sendResetLink() already no-ops silently for an
     * unknown address, and we don't want this endpoint to double as an
     * account-existence oracle for the response shape either.
     */
    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        summary: 'Request a password reset link',
        security: [['csrfToken' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email')],
        )),
        responses: [new OA\Response(response: 200, description: 'Link sent if the address is registered', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse'))],
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::broker('users')->sendResetLink(
            $request->only('email')
        );

        if (! in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER], true)) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __('If that email address is registered, a reset link has been sent.')]);
    }
}
