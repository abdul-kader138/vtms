<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer pages call /api/v1/* from this same site and rely on the
 * session cookie (Sanctum "stateful" SPA auth). Sanctum only does that for
 * hosts listed in SANCTUM_STATEFUL_DOMAINS, so opening the app at an
 * address missing from that list (e.g. localhost:8000 when only localhost
 * and 127.0.0.1:8000 are listed) made sign-in fail with "Session store not
 * set on request".
 *
 * A request whose Origin/Referer is this app's own host is first-party by
 * definition, so the host serving the request is always trusted. Other
 * origins still need to be listed; CSRF protection is unchanged.
 */
class TrustOwnHostAsStateful
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHttpHost();
        $stateful = config('sanctum.stateful', []);

        if ($host !== '' && ! in_array($host, $stateful, true)) {
            config(['sanctum.stateful' => [...$stateful, $host]]);
        }

        return $next($request);
    }
}
