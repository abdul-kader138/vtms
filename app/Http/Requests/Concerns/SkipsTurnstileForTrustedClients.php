<?php

namespace App\Http\Requests\Concerns;

use App\Rules\Turnstile;

/** Shared secrets shipped in a mobile binary cannot authenticate a trusted client. */
trait SkipsTurnstileForTrustedClients
{
    protected function turnstileRules(): array
    {
        return ['nullable', 'string', new Turnstile($this->ip())];
    }
}
