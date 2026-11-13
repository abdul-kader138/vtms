<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The OAuth access token the mobile app's own Google sign-in
            // SDK already obtained on-device — NOT the id_token some SDKs
            // also return. Verified server-side via Socialite's
            // userFromToken(), which calls Google's userinfo endpoint with
            // this as a bearer token.
            'access_token' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }
}
