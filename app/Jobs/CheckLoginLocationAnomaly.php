<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserSession;
use App\Services\IpGeolocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs after every successful learner login (see
 * App\Services\DeviceLockService) — off the request/response path, so a
 * slow or failed geolocation lookup never delays or breaks a login.
 *
 * This never blocks anything. It only logs a flagged entry to the same
 * audit trail LogAuthenticationActivity already writes to (visible to
 * admins under Audit Log) when the account's locked device logs in from a
 * different country than last time — the one IP-based signal that's
 * actually reliable: home-WiFi-to-mobile-data IP changes almost always
 * stay inside the same country, so a country change is a real signal
 * without the false positives a hard IP lock would cause.
 */
class CheckLoginLocationAnomaly implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $userId,
        private readonly string $ip,
    ) {}

    public function handle(IpGeolocationService $geolocation): void
    {
        $session = UserSession::where('user_id', $this->userId)->first();

        if (! $session) {
            // Reset or logged out again before this job ran — nothing to
            // compare against.
            return;
        }

        $country = $geolocation->country($this->ip);

        if (! $country) {
            // Lookup failed or the IP is private/local (e.g. local dev) —
            // leave whatever country was already on record alone rather
            // than overwriting good data with "unknown".
            return;
        }

        $previousCountry = $session->country;

        if ($previousCountry && $previousCountry !== $country) {
            activity('auth')
                ->causedBy(User::find($session->user_id))
                ->withProperties([
                    'ip' => $this->ip,
                    'previous_country' => $previousCountry,
                    'new_country' => $country,
                ])
                ->log('Unusual login location — country changed since last login');
        }

        $session->update(['country' => $country]);
    }
}
