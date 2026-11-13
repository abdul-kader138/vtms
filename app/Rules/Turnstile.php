<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Cloudflare Turnstile token server-side via the siteverify
 * endpoint. Skips verification entirely when TURNSTILE_SECRET_KEY is unset,
 * matching the "blank env = feature disabled" convention already used for
 * Google OAuth (see config/services.php) — keeps local dev captcha-free.
 *
 * $implicit = true is load-bearing: the field is declared 'nullable' so
 * requests work with no token at all when the secret is unset, but that
 * same 'nullable' makes Laravel's validator skip every other rule on the
 * field — including this one — whenever the value is absent. Without this
 * flag (which Illuminate\Validation\InvokableValidationRule::make() checks
 * to decide whether to wrap the rule as implicit), a request that omits
 * cf_turnstile_response entirely bypasses verification even with a real
 * secret configured, since this class's own blank-value check below never
 * gets the chance to run.
 */
class Turnstile implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly ?string $remoteIp = null) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $secret = config('services.turnstile.secret_key');

        if (blank($secret)) {
            return;
        }

        if (blank($value)) {
            $fail('Please complete the captcha challenge.');

            return;
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $value,
                'remoteip' => $this->remoteIp,
            ]);
        } catch (ConnectionException $e) {
            // Cloudflare being briefly unreachable (network blip, DNS,
            // their own outage) must not take down login/registration for
            // every customer — fail OPEN here specifically, same reasoning
            // the removed trusted-client bypass used to lean on: the
            // 'login'/'register' throttle is the real backstop against
            // abuse, this check is defence-in-depth on top of it. A
            // genuine "token rejected" response from Cloudflare below is
            // a different case and still fails closed.
            Log::warning('Turnstile siteverify unreachable, allowing request through', [
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful() || ! ($response->json('success') === true)) {
            Log::warning('Turnstile verification failed', [
                'errors' => $response->json('error-codes'),
            ]);

            $fail('The captcha challenge failed. Please try again.');
        }
    }
}
