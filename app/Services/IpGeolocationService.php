<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort, country-level IP geolocation for the login-anomaly check
 * (see App\Jobs\CheckLoginLocationAnomaly) — never used to block a login,
 * only to flag a locked account that suddenly logs in from a different
 * country for an admin to look at.
 *
 * Uses ip-api.com's free endpoint: no API key, no setup required. Trade-off
 * worth knowing: learner IP addresses are sent to that third-party service
 * for this lookup. Swap this class for a local MaxMind GeoLite2 database
 * lookup instead if that's not acceptable — nothing else in the app depends
 * on which one is used, only ::country() does.
 */
class IpGeolocationService
{
    public function country(string $ip): ?string
    {
        if (! $this->isPubliclyRoutable($ip)) {
            return null;
        }

        try {
            $response = Http::timeout(3)
                ->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,countryCode']);

            if (! $response->ok() || $response->json('status') !== 'success') {
                return null;
            }

            return $response->json('countryCode');
        } catch (\Throwable $e) {
            // Never let a geolocation hiccup surface as a failed login or a
            // failed job — this is a monitoring nicety, not a requirement.
            Log::warning('IP geolocation lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function isPubliclyRoutable(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
