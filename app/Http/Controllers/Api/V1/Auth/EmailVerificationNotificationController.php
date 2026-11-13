<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EmailVerificationNotificationController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/email/verification-notification',
        summary: 'Resend the email verification link',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Auth'],
        responses: [new OA\Response(response: 200, description: 'Link sent (or already verified)', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse'))],
    )]
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => __('Email already verified.')]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => __('Verification link sent.')]);
    }
}
