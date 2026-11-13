<?php

namespace App\Providers;

use App\Filament\Auth\LoginResponse;
use App\Listeners\LogAuthenticationActivity;
use App\Listeners\LogPermissionActivity;
use App\Models\Setting;
use App\Policies\ActivityPolicy;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The panel is the site's only login now — see App\Filament\Auth\LoginResponse.
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin labels are written in English (explicitly, or derived by
        // Filament from the field name); route them all through the JSON
        // catalogs so the panel follows the user's language.
        foreach ([
            \Filament\Forms\Components\Field::class,
            \Filament\Tables\Columns\Column::class,
            \Filament\Tables\Filters\BaseFilter::class,
            \Filament\Infolists\Components\Entry::class,
        ] as $component) {
            $component::configureUsing(fn ($instance) => $instance->translateLabel());
        }

        // Surface N+1s as exceptions everywhere except production, where it
        // stays a silent no-op so a missed eager-load only degrades
        // performance rather than 500-ing a customer. Not the full
        // shouldBeStrict() — preventAccessingMissingAttributes breaks the
        // column-narrowed selects the dashboard widgets/export service use.
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->applyMailSettings();
        $this->applyGoogleOAuthSettings();

        // Every password field in the app — registration, forgot-password
        // reset, profile password change, and admin-created users — calls
        // Password::default() (Filament's own auth pages do this out of the
        // box; UserResource opts in explicitly). Without this, that default
        // is just Password::min(8) with no actual strength requirement.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers());

        Event::subscribe(LogAuthenticationActivity::class);
        Event::subscribe(LogPermissionActivity::class);

        // Activity (Spatie\Activitylog\Models\Activity) lives outside
        // App\Models, so Laravel's policy auto-discovery never finds the
        // shield:generate'd App\Policies\ActivityPolicy on its own —
        // ActivityLogResource's viewAny/view authorization depends on this.
        Gate::policy(Activity::class, ActivityPolicy::class);

        $this->registerRateLimiters();

        // Every /api/v1/* endpoint returns one resource per response (no
        // batch/collection endpoints yet), so the default {"data": {...}}
        // envelope only adds a layer the SPA would immediately unwrap.
        JsonResource::withoutWrapping();

    }

    /**
     * Named limiters for the customer-facing API.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // A 6-digit TOTP code has a small enough space that this endpoint
        // needs its own tight limiter — keyed by the challenge token itself
        // (not email/IP) since that's what's actually being brute-forced.
        RateLimiter::for('2fa-challenge', fn ($request) => Limit::perMinute(5)->by($request->input('challenge_token').'|'.$request->ip()));

        // Every one of these verifies a secret the account already has
        // (current password via Hash::check, or a 6-digit TOTP code) —
        // the same brute-force shape as 'login' and '2fa-challenge'
        // above, just on already-authenticated routes that had been
        // riding on the generic 120/min 'api' ceiling with no limiter of
        // their own. Keyed per user, since these all require auth:sanctum.
        RateLimiter::for('account-security', fn ($request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Mail config is read by Laravel's mailer once per process and cached
     * internally, so it must be pushed into config() at boot rather than
     * read on demand like other settings.
     */
    private function applyMailSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        config([
            'mail.from.name' => Setting::get('mail_from_name', config('mail.from.name')),
            'mail.from.address' => Setting::get('mail_from_address', config('mail.from.address')),
        ]);

        $provider = Setting::get('mail_provider', 'smtp');
        $configuredVendors = Setting::get('mail_vendors', []);
        $hasVendorProfiles = is_array($configuredVendors) && $configuredVendors !== [];

        if (! $hasVendorProfiles && $provider === 'log') {
            config(['mail.default' => 'log']);

            return;
        }

        if (! $hasVendorProfiles && $provider === 'brevo') {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
                'mail.mailers.smtp.port' => 587,
                'mail.mailers.smtp.username' => Setting::get('brevo_smtp_username'),
                'mail.mailers.smtp.password' => Setting::get('brevo_smtp_key') ?: Setting::get('brevo_api_key'),
                'mail.mailers.smtp.scheme' => 'smtp',
            ]);

            return;
        }

        $vendors = $configuredVendors;
        $activeVendorKey = Setting::get('mail_active_vendor', Setting::get('mail_provider', 'smtp'));
        $activeVendor = is_array($vendors)
            ? collect($vendors)->first(fn ($vendor) => ($vendor['key'] ?? null) === $activeVendorKey)
            : null;

        // Compatibility with installations that have not yet saved the new
        // vendor profile setting.
        if (! is_array($activeVendor)) {
            if (is_array($vendors) && $vendors !== []) {
                // Never silently send through a different provider when the
                // selected profile was removed or renamed.
                config(['mail.default' => 'log']);

                return;
            }

            $activeVendor = [
                'transport' => 'smtp',
                'host' => Setting::get('mail_host'),
                'port' => Setting::get('mail_port', 587),
                'username' => Setting::get('mail_username'),
                'password' => Setting::get('mail_password'),
                'encryption' => Setting::get('mail_encryption', 'tls'),
            ];
        }

        if (($activeVendor['transport'] ?? 'smtp') === 'log') {
            config(['mail.default' => 'log']);

            return;
        }

        if ($host = ($activeVendor['host'] ?? null)) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => $activeVendor['port'] ?? 587,
                'mail.mailers.smtp.username' => $activeVendor['username'] ?? null,
                'mail.mailers.smtp.password' => $activeVendor['password'] ?? null,
                // The stored value is the admin-facing choice ("tls"/"ssl" —
                // see SystemSettings' encryption select), NOT a valid Symfony
                // Mailer scheme. Symfony only accepts "smtp" (STARTTLS,
                // negotiated automatically — what "TLS" on port 587 means in
                // practice) or "smtps" (implicit TLS, port 465 — what "SSL"
                // means). Passing "tls"/"ssl" straight through throws
                // UnsupportedSchemeException and silently fails every queued
                // mail job.
                'mail.mailers.smtp.scheme' => ($activeVendor['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            ]);
        }
    }

    /**
     * Same rationale as applyMailSettings(): Socialite resolves
     * config('services.google.*') once when its driver is built, so
     * admin-panel-configured credentials (System Settings → Security)
     * must land in config() at boot to take effect. Falls through to
     * whatever .env already provided when a setting is blank — either
     * source works, DB just wins if both are set.
     */
    private function applyGoogleOAuthSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        config([
            'services.google.client_id' => Setting::get('google_client_id') ?: config('services.google.client_id'),
            'services.google.client_secret' => Setting::get('google_client_secret') ?: config('services.google.client_secret'),
        ]);
    }
}
