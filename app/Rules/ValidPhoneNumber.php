<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidPhoneNumber implements ValidationRule
{
    public function __construct(private readonly mixed $region = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isValid($value, $this->region)) {
            $fail($this->region
                ? 'The :attribute is not a valid number for the selected country.'
                : 'The :attribute must be a valid international phone number with country code.');
        }
    }
}
