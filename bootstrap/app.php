<?php

use App\Http\Middleware\EnsureSingleDevice;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrustOwnHostAsStateful;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before Sanctum decides whether an API request may use the session.
        $middleware->prepend(TrustOwnHostAsStateful::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: SetLocale::class);
        $middleware->api(append: SetLocale::class);
        $middleware->alias(['single.device' => EnsureSingleDevice::class]);

        // Customer pages send guests to the customer sign-in; everything else
        // (the /panel and staff-only downloads such as receipts, PDFs and
        // import samples) to the staff sign-in.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('app', 'app/*')
            ? route('customer.login')
            : route('filament.admin.auth.login'));

        // Lets the customer-facing React auth shell authenticate against
        // /api/v1/* with the same session cookie as the web guard, instead
        // of issuing bearer tokens for a first-party frontend.
        $middleware->statefulApi();

        // Applies the 'api' rate limiter (defined in AppServiceProvider) to
        // every /api/* route by default; individual routes can still opt
        // into a stricter named limiter (e.g. 'login', 'search').
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
