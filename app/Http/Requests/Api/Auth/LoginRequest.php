<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Concerns\SkipsTurnstileForTrustedClients;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use SkipsTurnstileForTrustedClients;

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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'cf_turnstile_response' => $this->turnstileRules(),
        ];
    }
}
