<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Dashboard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel lives at "/", which is the Filament dashboard route. Staff
 * see the dashboard; customer / self-registered accounts must not (see
 * Dashboard::canAccess()). Without this they'd get a bare 403 when they open
 * the site root — instead, bounce them to the customer sign-in screen, since
 * there is no customer-facing dashboard in this app.
 *
 * Registered in App\Providers\Filament\AdminPanelProvider->authMiddleware(),
 * so auth()->user() is always resolved by the time this runs.
 */
class RedirectNonAdminsFromDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        // Run before Filament's Authenticate middleware so an authenticated
        // non-staff account gets the friendly redirect instead of a 403.
        // Guests continue to Filament's login page.
        if ($request->routeIs('filament.admin.pages.dashboard')
            && auth()->check()
            && ! Dashboard::canAccess()) {
            return redirect()->route('customer.login');
        }

        return $next($request);
    }
}
