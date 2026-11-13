<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Concerns\SkipsTurnstileForTrustedClients;
use App\Rules\ValidPhoneNumber;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use SkipsTurnstileForTrustedClients;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'), $this->input('phone_country'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', new ValidPhoneNumber($this->input('phone_country'))],
            'password' => ['required', 'confirmed', Password::default()],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'cf_turnstile_response' => $this->turnstileRules(),
        ];
    }
}
