<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\CustomerResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class RegisteredUserController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a new customer account',
        description: "Does not sign the user in — an unverified account can't hold a session. A verification email is sent immediately; log in only succeeds once it's clicked.",
        security: [['csrfToken' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['first_name', 'last_name', 'email', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'first_name', type: 'string', example: 'Ada'),
                new OA\Property(property: 'last_name', type: 'string', example: 'Lovelace'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@example.com'),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
                new OA\Property(property: 'phone_country', description: 'ISO country code; required when phone has no + country code', type: 'string', nullable: true, example: 'IT'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Password!123'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'Password!123'),
                new OA\Property(property: 'marketing_opt_in', type: 'boolean', nullable: true),
                new OA\Property(property: 'cf_turnstile_response', description: 'Cloudflare Turnstile token. Required when GET /api/v1/auth/meta reports captcha_required. Tokens are single-use.', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Account created', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Self-registered accounts get no Filament role, which is what already
     * keeps them out of the admin panel (User::canAccessPanel() requires
     * one) — staff accounts are provisioned deliberately instead.
     *
     * Registration does NOT sign the user in: an unverified account can't
     * hold a session (see AuthenticatedSessionController::store()). The
     * response carries the created customer with `email_verified: false` so
     * the SPA can send the user straight to a "verify your email" screen;
     * the verification link works with no session (see routes/api.php).
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'marketing_opt_in' => $data['marketing_opt_in'] ?? false,
            'password' => $data['password'],
        ]);
        $user->markAsCustomer();

        $user->sendEmailVerificationNotification();

        return (new CustomerResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
