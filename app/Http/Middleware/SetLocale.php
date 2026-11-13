<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the request's language, most specific first: the signed-in user's
 * saved choice, a guest's choice from the locale switcher (session), the
 * client's Accept-Language (how the mobile app asks), then the site default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->userLocale($request)
            ?? ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? ($request->header('Accept-Language') ? $request->getPreferredLanguage(User::SUPPORTED_LOCALES) : null)
            ?? $this->defaultLocale();

        App::setLocale(in_array($locale, User::SUPPORTED_LOCALES, true) ? $locale : 'en');

        $response = $next($request);
        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }

    private function userLocale(Request $request): ?string
    {
        // The default guard covers web and session-backed API calls; the
        // sanctum guard covers the mobile app's bearer tokens.
        $user = $request->user() ?? ($request->bearerToken() ? $request->user('sanctum') : null);

        return $user?->locale;
    }

    private function defaultLocale(): string
    {
        try {
            return Schema::hasTable('settings')
                ? Setting::get('default_locale', config('app.locale', 'en'))
                : config('app.locale', 'en');
        } catch (\Throwable) {
            return config('app.locale', 'en');
        }
    }
}
