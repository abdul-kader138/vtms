<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Account\UpdateAvatarRequest;
use App\Http\Resources\CustomerResource;
use App\Models\User;
use App\Rules\ValidPhoneNumber;
use App\Services\Account\AccountDataService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AccountController extends Controller
{
    #[OA\Get(
        path: '/api/v1/account',
        summary: 'Get the current customer profile',
        security: [['sanctumSession' => []]],
        tags: ['Account'],
        responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Customer'))],
    )]
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource($request->user());
    }

    #[OA\Put(
        path: '/api/v1/account',
        summary: 'Update the current customer profile',
        description: 'Changing the email address clears verification and sends a new confirmation link.',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'first_name', type: 'string'),
            new OA\Property(property: 'last_name', type: 'string'),
            new OA\Property(property: 'phone', type: 'string', nullable: true),
            new OA\Property(property: 'phone_country', description: 'ISO country code; required when phone has no + country code', type: 'string', nullable: true, example: 'IT'),
            new OA\Property(property: 'marketing_opt_in', type: 'boolean'),
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'locale', type: 'string', nullable: true, enum: ['en', 'it', 'bn']),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(Request $request): CustomerResource
    {
        // Normalize the phone before validation so account data is consistent.
        if ($request->has('phone')) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'), $request->input('phone_country'))]);
        }

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', new ValidPhoneNumber($request->input('phone_country'))],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'tax_reminders_enabled' => ['sometimes', 'boolean'],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
            // Mirrors the Filament profile page's own locale select (see
            // App\Filament\Auth\EditProfile) — the same preference, now
            // reachable from the customer SPA/mobile app too.
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(User::SUPPORTED_LOCALES)],
        ]);

        $user = $request->user();
        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;

        $user->fill($data);

        // Changing address invalidates the old verification — re-confirm
        // the new one belongs to the account owner before it's trusted.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return new CustomerResource($user);
    }

    #[OA\Post(
        path: '/api/v1/account/avatar',
        summary: 'Upload or replace the current customer avatar',
        description: 'Multipart upload. The previous avatar file (if any) is deleted once the new one is stored.',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['avatar'],
                properties: [new OA\Property(property: 'avatar', type: 'string', format: 'binary', description: 'JPG, PNG or WEBP, up to 5MB.')],
            ),
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Customer')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateAvatar(UpdateAvatarRequest $request): CustomerResource
    {
        $user = $request->user();
        $previousAvatar = $user->avatar;

        $user->forceFill([
            'avatar' => $request->file('avatar')->store('avatars', 'public'),
        ])->save();

        if ($previousAvatar) {
            Storage::disk('public')->delete($previousAvatar);
        }

        return new CustomerResource($user);
    }

    #[OA\Delete(
        path: '/api/v1/account/avatar',
        summary: 'Remove the current customer avatar',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        responses: [new OA\Response(response: 200, description: 'Removed', content: new OA\JsonContent(ref: '#/components/schemas/Customer'))],
    )]
    public function destroyAvatar(Request $request): CustomerResource
    {
        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->forceFill(['avatar' => null])->save();
        }

        return new CustomerResource($user);
    }

    #[OA\Put(
        path: '/api/v1/account/password',
        summary: 'Change the current customer password',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password updated', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 422, description: 'Current password incorrect, or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The current password is incorrect.')],
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();
        // A password change invalidates previously issued bearer credentials,
        // including tokens copied from a lost or compromised device.
        $user->tokens()->delete();

        return response()->json(['message' => __('Password updated.')]);
    }

    /**
     * GDPR data export for the account.
     */
    #[OA\Get(
        path: '/api/v1/account/export',
        summary: 'Export all account data (GDPR)',
        security: [['sanctumSession' => []]],
        tags: ['Account'],
        responses: [new OA\Response(response: 200, description: 'Account data', content: new OA\JsonContent(type: 'object'))],
    )]
    public function export(Request $request, AccountDataService $accountData): JsonResponse
    {
        return response()->json($accountData->export($request->user()));
    }

    /**
     * GDPR erasure request. Requires the current password as confirmation —
     * this is destructive-in-effect (every identifying field is
     * overwritten) even though the row itself is anonymized rather than
     * dropped; see AccountDataService::anonymize() for why.
     */
    #[OA\Delete(
        path: '/api/v1/account',
        summary: 'Delete (anonymize) the current account',
        description: 'Requires the current password as proof of intent. Anonymizes the account record rather than hard-deleting it, then ends the session.',
        security: [['sanctumSession' => [], 'csrfToken' => []]],
        tags: ['Account'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password'],
            properties: [new OA\Property(property: 'password', type: 'string', format: 'password')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Account deleted', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 422, description: 'Incorrect password', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function destroy(Request $request, AccountDataService $accountData): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('The password is incorrect.')],
            ]);
        }

        $accountData->anonymize($user);

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('Your account has been deleted.')]);
    }
}
