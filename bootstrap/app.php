<?php

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
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: \App\Http\Middleware\SetLocale::class);
        $middleware->api(append: \App\Http\Middleware\SetLocale::class);
        $middleware->alias(['single.device' => \App\Http\Middleware\EnsureSingleDevice::class]);

        // The panel is the site's only staff login — see App\Filament\Auth\LoginResponse.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

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
