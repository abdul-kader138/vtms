<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class SystemSettings extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?int $navigationSort = 40;
    protected static string $view = 'filament.pages.system-settings';

    public ?array $data = [];

    public static function getNavigationLabel(): string { return __('System Settings'); }
    public static function getNavigationGroup(): ?string { return __('Administration'); }
    public function getTitle(): string { return __('System Settings'); }

    public function mount(): void
    {
        $this->form->fill([
            'app_name' => Setting::get('app_name', 'VTMS'),
            'app_tagline' => Setting::get('app_tagline', ''),
            'app_logo' => Setting::get('app_logo'),
            'app_icon' => Setting::get('app_icon'),
            'favicon' => Setting::get('favicon'),
            'admin_theme' => Setting::get('admin_theme', 'indigo'),
            'admin_panel_theme_mode' => Setting::get('admin_panel_theme_mode', 'dark'),
            'single_device_switch_cooldown_minutes' => Setting::get('single_device_switch_cooldown_minutes', 10),
            'customer_device_lock_enabled' => Setting::get('customer_device_lock_enabled', true),
            'mail_provider' => Setting::get('mail_provider', 'smtp'),
            'mail_from_name' => Setting::get('mail_from_name', ''),
            'mail_from_address' => Setting::get('mail_from_address', ''),
            'brevo_smtp_username' => Setting::get('brevo_smtp_username', ''),
            // Secrets intentionally are not filled back into the browser. A
            // blank value on save means "keep the existing credential".
            'brevo_smtp_key' => '',
            'mail_active_vendor' => Setting::get('mail_active_vendor', Setting::get('mail_provider', 'smtp')),
            'mail_vendors' => $this->getMailVendors(),
            'google_client_id' => Setting::get('google_client_id', ''),
            'google_client_secret' => Setting::get('google_client_secret', ''),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('Branding'))->description(__('These settings control the admin panel and customer-facing screens.'))->schema([
                TextInput::make('app_name')->label(__('Application name'))->required(),
                TextInput::make('app_tagline')->label(__('Tagline'))->maxLength(160),
                FileUpload::make('app_logo')->label(__('Logo'))->image()->disk('public')->directory('branding')->visibility('public')->maxSize(5120)->helperText(__('Used in the header, footer and login page. PNG, JPG or WEBP.')),
                FileUpload::make('app_icon')->label(__('App icon'))->image()->disk('public')->directory('branding')->visibility('public')->maxSize(2048)->helperText(__('Used in compact branding areas. Square PNG or WEBP recommended.')),
                FileUpload::make('favicon')->label(__('Browser favicon'))->image()->disk('public')->directory('branding')->visibility('public')->maxSize(2048)->helperText(__('Shown in the browser tab. Square PNG, ICO or WEBP. Overrides the app icon for browser tabs.')),
                Select::make('admin_theme')->label(__('Brand color'))->options([
                    'indigo' => 'Indigo', 'emerald' => 'Emerald', 'teal' => 'Teal', 'sky' => 'Sky',
                    'violet' => 'Violet', 'rose' => 'Rose', 'amber' => 'Amber', 'orange' => 'Orange',
                    'blue' => 'Blue', 'cyan' => 'Cyan', 'purple' => 'Purple', 'pink' => 'Pink',
                ])->native(false)->required(),
                Select::make('admin_panel_theme_mode')->label(__('Theme mode'))->options([
                    'light' => 'Light', 'dark' => 'Dark', 'system' => 'System',
                    'sepia' => 'Sepia', 'midnight' => 'Midnight', 'high_contrast' => 'High contrast',
                ])->native(false)->required(),
            ])->columns(2),
            Section::make(__('Account security'))->description(__('Prevent rapid device switching when credentials are shared.'))->schema([
                Toggle::make('customer_device_lock_enabled')
                    ->label(__('Lock customer accounts to one device'))
                    ->default(true)
                    ->helperText(__('Once a customer account signs in, it is locked to that device. Signing in from any other device is refused until an admin resets it from the Users list. Does not apply to staff/admin accounts, which use their own cooldown-based device check instead.')),
                TextInput::make('single_device_switch_cooldown_minutes')
                    ->label(__('Device switch cooldown (minutes)'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(1440)
                    ->required()
                    ->helperText(__('Applies to staff/admin accounts. For customers, only applies while the hard device lock above is off — a different device cannot take over during this period. Use 0 to allow immediate switching.')),
            ]),
            Section::make(__('Email and Brevo'))->schema([
                Select::make('mail_active_vendor')
                    ->label(__('Active email provider'))
                    ->options(fn (Get $get): array => collect($get('mail_vendors') ?? [])->filter(fn ($vendor) => filled($vendor['key'] ?? null))->mapWithKeys(fn ($vendor) => [$vendor['key'] => $vendor['label'] ?? $vendor['key']])->put('log', 'Log only (development)')->all())
                    ->native(false)
                    ->required()
                    ->helperText(__('Only the selected vendor sends outgoing email.')),
                Repeater::make('mail_vendors')
                    ->label(__('Email vendor profiles'))
                    ->live()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('key')->label(__('Vendor key'))->required()->alphaDash()->maxLength(50)->helperText(__('Example: brevo')),
                            TextInput::make('label')->label(__('Display name'))->required()->maxLength(100),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('host')->label(__('SMTP host'))->maxLength(255)->placeholder('smtp-relay.brevo.com'),
                            TextInput::make('port')->label(__('SMTP port'))->numeric()->default(587),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('username')->label(__('SMTP username'))->maxLength(255),
                            TextInput::make('password')->label(__('SMTP password / SMTP key'))->password()->revealable()->autocomplete('new-password')->maxLength(255)->helperText(__('For Brevo use the SMTP key, not the API key.')),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('encryption')->label(__('Encryption'))->options(['tls' => 'TLS / STARTTLS', 'ssl' => 'SSL', 'none' => 'None'])->default('tls')->native(false),
                            Select::make('transport')->label(__('Transport'))->options(['smtp' => 'SMTP', 'log' => 'Log only'])->default('smtp')->native(false),
                        ]),
                    ])
                    ->defaultItems(1)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                    ->addActionLabel(__('Add email provider'))
                    ->collapsible()
                    ->reorderable(false)
                    ->required(),
                TextInput::make('mail_from_name')->label(__('Sender name')),
                TextInput::make('mail_from_address')->label(__('Sender email'))->email(),
            ])->columns(2),
            Section::make(__('Google sign-in'))->schema([
                TextInput::make('google_client_id')->label(__('Google client ID')),
                TextInput::make('google_client_secret')->label(__('Google client secret'))->password()->revealable(),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $existingVendors = Setting::get('mail_vendors', []);

        if (is_array($data['mail_vendors'] ?? null) && is_array($existingVendors)) {
            $data['mail_vendors'] = collect($data['mail_vendors'])->map(function (array $vendor) use ($existingVendors): array {
                if (blank($vendor['password'] ?? null)) {
                    $old = collect($existingVendors)->firstWhere('key', $vendor['key'] ?? null);
                    $vendor['password'] = $old['password']
                        ?? (($vendor['key'] ?? null) === 'brevo'
                            ? (Setting::get('brevo_smtp_key') ?: Setting::get('brevo_api_key', ''))
                            : Setting::get('mail_password', ''));
                }

                return $vendor;
            })->all();
        }

        foreach ($data as $key => $value) {
            if ($key === 'brevo_smtp_key' && blank($value)) {
                continue;
            }

            Setting::set($key, $value ?? '', match ($key) {
                'mail_provider', 'brevo_smtp_username', 'brevo_smtp_key', 'mail_active_vendor', 'mail_vendors', 'mail_from_name', 'mail_from_address' => 'email',
                'google_client_id', 'google_client_secret' => 'google',
                'app_name', 'app_tagline', 'app_logo', 'app_icon', 'favicon', 'admin_theme', 'admin_panel_theme_mode' => 'appearance',
                'single_device_switch_cooldown_minutes', 'customer_device_lock_enabled' => 'security',
                default => 'general',
            });
        }

        // Queue workers are long-lived and load mail configuration only when
        // they boot. Tell them to finish their current job and restart so a
        // newly selected Brevo/vendor profile is used immediately.
        try {
            Artisan::call('queue:restart');
        } catch (\Throwable) {
            // Saving settings must still succeed when a queue backend is not
            // available during local setup.
        }

        Notification::make()->success()->title(__('Settings saved'))->send();
    }

    private function getMailVendors(): array
    {
        $vendors = Setting::get('mail_vendors');

        if (is_array($vendors) && $vendors !== []) {
            return collect($vendors)->map(function (array $vendor): array {
                // Never place stored SMTP credentials back into the browser.
                $vendor['password'] = '';

                return $vendor;
            })->all();
        }

        $legacyProvider = Setting::get('mail_provider', 'smtp');

        return [[
            'key' => $legacyProvider === 'brevo' ? 'brevo' : 'smtp',
            'label' => $legacyProvider === 'brevo' ? 'Brevo' : 'SMTP',
            'transport' => $legacyProvider === 'log' ? 'log' : 'smtp',
            'host' => $legacyProvider === 'brevo' ? 'smtp-relay.brevo.com' : Setting::get('mail_host', ''),
            'port' => $legacyProvider === 'brevo' ? 587 : Setting::get('mail_port', 587),
            'username' => $legacyProvider === 'brevo' ? Setting::get('brevo_smtp_username') : Setting::get('mail_username', ''),
            'password' => '',
            'encryption' => $legacyProvider === 'brevo' ? 'tls' : Setting::get('mail_encryption', 'tls'),
        ]];
    }
}
