<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\EmailVerificationPrompt;
use App\Filament\Auth\Login;
use App\Filament\Auth\ResetPassword;
use App\Http\Middleware\EnsureSingleDevice;
use App\Http\Middleware\RedirectNonAdminsFromDashboard;
use App\Http\Middleware\SetLocale;
use App\Models\Setting;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    // ── Available admin color themes ──────────────────────────────────────────
    public static array $themes = [
        'indigo' => ['label' => 'Indigo',     'color' => 'indigo'],
        'amber' => ['label' => 'Amber Gold', 'color' => 'amber'],
        'emerald' => ['label' => 'Emerald',    'color' => 'emerald'],
        'rose' => ['label' => 'Rose',       'color' => 'rose'],
        'violet' => ['label' => 'Violet',     'color' => 'violet'],
        'sky' => ['label' => 'Sky Blue',   'color' => 'sky'],
        'teal' => ['label' => 'Teal',       'color' => 'teal'],
        'orange' => ['label' => 'Orange',     'color' => 'orange'],
        'slate' => ['label' => 'Slate',      'color' => 'slate'],
        'gray' => ['label' => 'Gray',       'color' => 'gray'],
        'blue' => ['label' => 'Blue',       'color' => 'blue'],
        'cyan' => ['label' => 'Cyan',       'color' => 'cyan'],
        'purple' => ['label' => 'Purple',     'color' => 'purple'],
        'pink' => ['label' => 'Pink',       'color' => 'pink'],
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(Login::class)
            ->passwordReset(RequestPasswordReset::class, ResetPassword::class)
            ->emailVerification(EmailVerificationPrompt::class)
            ->profile(EditProfile::class, isSimple: false)
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->brandName(fn () => Setting::get('app_name', 'VTMS'))
            ->brandLogo(fn () => self::resolveBrandLogoUrl())
            ->favicon(fn () => self::resolveFaviconUrl())
            ->colors([
                'primary' => self::resolveThemeColor(),
            ])
            ->defaultThemeMode(self::resolveDefaultThemeMode())
            ->darkMode(...array_values(self::resolveDarkModeArgs()))
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn () => self::resolveAdminPanelModeStyles(),
            )
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('15rem')
            ->navigationGroups([
                NavigationGroup::make(fn () => __('Tax Calculations'))
                    ->icon('heroicon-o-rectangle-stack'),
                NavigationGroup::make(fn () => __('filament-shield::filament-shield.nav.group'))
                    ->icon('heroicon-o-shield-check'),
                NavigationGroup::make(fn () => __('Administration'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                EnsureSingleDevice::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_START,
                fn () => view('components.auth-brand-panel'),
                scopes: [
                    Login::class,
                    RequestPasswordReset::class,
                    ResetPassword::class,
                    EmailVerificationPrompt::class,
                ],
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn () => self::resolveGoogleAuthButton(),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn () => view('components.language-switcher'),
            )
            ->authMiddleware([
                RedirectNonAdminsFromDashboard::class,
                Authenticate::class,
            ]);
    }

    // Wrapped in try/catch throughout: this provider boots on every artisan
    // command, including the very first `migrate` on a fresh install, before
    // the settings table exists.

    protected static function resolveThemeColor(): array
    {
        $colorMap = [
            'indigo' => Color::Indigo,
            'amber' => Color::Amber,
            'emerald' => Color::Emerald,
            'rose' => Color::Rose,
            'violet' => Color::Violet,
            'sky' => Color::Sky,
            'teal' => Color::Teal,
            'orange' => Color::Orange,
            'slate' => Color::Slate,
            'gray' => Color::Gray,
            'blue' => Color::Blue,
            'cyan' => Color::Cyan,
            'purple' => Color::Purple,
            'pink' => Color::Pink,
        ];

        try {
            $theme = Setting::get('admin_theme', 'indigo');
        } catch (\Throwable) {
            $theme = 'indigo';
        }

        return $colorMap[$theme] ?? Color::Indigo;
    }

    // ── Panel dark-mode resolution ────────────────────────────────────────────
    public static function resolveNativeThemeModeKey(?string $mode = null): string
    {
        return match ($mode) {
            'light', 'sepia' => 'light',
            'dark', 'high_contrast', 'midnight' => 'dark',
            'system' => 'system',
            default => 'dark',
        };
    }

    protected static function resolveDefaultThemeMode(): ThemeMode
    {
        try {
            $mode = Setting::get('admin_panel_theme_mode', 'dark');
        } catch (\Throwable) {
            $mode = 'dark';
        }

        return match (self::resolveNativeThemeModeKey($mode)) {
            'light' => ThemeMode::Light,
            'system' => ThemeMode::System,
            default => ThemeMode::Dark,
        };
    }

    protected static function resolveDarkModeArgs(): array
    {
        try {
            $mode = Setting::get('admin_panel_theme_mode', 'dark');
        } catch (\Throwable) {
            $mode = 'dark';
        }

        return match (self::resolveNativeThemeModeKey($mode)) {
            'light' => ['condition' => false, 'isForced' => false],
            'system' => ['condition' => true,  'isForced' => false],
            default => ['condition' => true,  'isForced' => true],
        };
    }

    // ── Extra CSS injected after Filament styles (panel mode overrides) ───────
    protected static function resolveAdminPanelModeStyles(): string
    {
        try {
            $mode = Setting::get('admin_panel_theme_mode', 'dark');
        } catch (\Throwable) {
            $mode = 'dark';
        }

        $custom = match ($mode) {
            'high_contrast' => <<<'CSS'
<style>
    .fi-body {
        background: #020617 !important;
        color: #f8fafc !important;
        color-scheme: dark;
    }
    .fi-topbar, .fi-sidebar, .fi-header, .fi-page, .fi-main, .fi-simple-main {
        background: #020617 !important;
        border-color: rgba(148,163,184,.22) !important;
    }
    .fi-sidebar-item-label, .fi-sidebar-group-label { color: #e2e8f0 !important; }
</style>
CSS,
            'sepia' => <<<'CSS'
<style>
    .fi-body {
        background: linear-gradient(180deg, #f7efe4 0%, #efe3d2 100%) !important;
        color: #4b3621 !important;
        color-scheme: light;
    }
    .fi-topbar, .fi-sidebar, .fi-header, .fi-page, .fi-main, .fi-simple-main {
        background: #f9f2e8 !important;
        border-color: rgba(120,86,56,.16) !important;
    }
</style>
CSS,
            'midnight' => <<<'CSS'
<style>
    .fi-body {
        background: linear-gradient(180deg, #020617 0%, #0f172a 55%, #111827 100%) !important;
        color: #e5e7eb !important;
        color-scheme: dark;
    }
    .fi-topbar, .fi-sidebar, .fi-header, .fi-page, .fi-main, .fi-simple-main {
        background: rgba(15,23,42,.96) !important;
        border-color: rgba(96,165,250,.18) !important;
    }
</style>
CSS,
            default => '',
        };

        // Always applied, regardless of theme mode:
        //  - hide the built-in theme switcher (managed via System Settings)
        //  - left sidebar polish: tighter group labels, rounded items with a
        //    smooth hover, and a brand-accented active state with a left bar.
        //    Scoped to .fi-sidebar and uses Filament's own --primary-* vars so
        //    it follows the configured brand colour and both light/dark modes.
        $base = <<<'CSS'
<style>
    .fi-theme-switcher { display: none !important; }

    .fi-sidebar-nav { gap: .125rem; padding-top: .5rem; }

    .fi-sidebar-group + .fi-sidebar-group { margin-top: 1rem; }

    .fi-sidebar-group-label {
        font-size: .6875rem;
        font-weight: 600;
        letter-spacing: .08em;
        text-transform: uppercase;
        opacity: .6;
    }

    .fi-sidebar-item-button {
        border-radius: .5rem;
        transition: background-color .15s ease, color .15s ease;
        position: relative;
    }

    .fi-sidebar-item-button:hover {
        background-color: rgb(var(--gray-500) / .08);
    }

    .fi-sidebar-item-icon { transition: color .15s ease; }

    .fi-sidebar-item.fi-active .fi-sidebar-item-button,
    .fi-sidebar-item-button.fi-active {
        background-color: rgb(var(--primary-500) / .12);
        font-weight: 600;
    }

    .fi-sidebar-item.fi-active .fi-sidebar-item-button::before,
    .fi-sidebar-item-button.fi-active::before {
        content: "";
        position: absolute;
        inset-inline-start: -.5rem;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 1.25rem;
        border-radius: 9999px;
        background-color: rgb(var(--primary-500));
    }

    .fi-sidebar-item.fi-active .fi-sidebar-item-icon,
    .fi-sidebar-item-button.fi-active .fi-sidebar-item-icon {
        color: rgb(var(--primary-500));
    }
</style>
CSS;

        return $base.$custom;
    }

    // Only render the button once GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET are
    // actually set — otherwise clicking it just throws (Socialite has
    // nothing to redirect to), which is a worse experience than not
    // showing it on a fresh install that hasn't configured OAuth yet.
    protected static function resolveGoogleAuthButton(): View|string
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            return '';
        }

        return view('components.google-auth-button');
    }

    protected static function resolveBrandLogoUrl(): ?string
    {
        try {
            $path = Setting::get('app_logo');
        } catch (\Throwable) {
            return null;
        }

        return $path ? Storage::disk('public')->url($path) : null;
    }

    // "favicon" overrides "app_icon" when both are set — matches the field
    // helper text in SystemSettings ("Overrides the app icon for browser tabs").
    protected static function resolveFaviconUrl(): ?string
    {
        try {
            $path = Setting::get('favicon') ?: Setting::get('app_icon');
        } catch (\Throwable) {
            return null;
        }

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
