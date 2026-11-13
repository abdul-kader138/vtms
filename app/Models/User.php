<?php

namespace App\Models;

use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['customer_id', 'device_lock_exempt', 'tax_reminders_enabled', 'first_name', 'last_name', 'email', 'phone', 'tax_id', 'address', 'marketing_opt_in', 'password', 'avatar', 'google_id', 'email_verified_at', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, HasLocalePreference, HasName, MustVerifyEmail
{
    public const SUPPORTED_LOCALES = ['en', 'it', 'bn'];

    /** Portal accounts: no permissions, never the admin panel. */
    public const CUSTOMER_ROLE = 'customer';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    use \Illuminate\Auth\MustVerifyEmail;

    // Tracks dispatch only on this model instance. The Filament registration
    // flow fires Registered before calling its own notification hook; the
    // hook uses this marker as a safe fallback when the framework listener is
    // not registered (or has been replaced by an application event cache).
    protected bool $verificationNotificationDispatched = false;

    protected static function booted(): void
    {
        static::updated(function (self $user): void {
            if ($user->isDirty('avatar') && filled($user->getOriginal('avatar'))) {
                Storage::disk('public')->delete($user->getOriginal('avatar'));
            }
        });

        static::deleted(function (self $user): void {
            if (filled($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_lock_exempt' => 'boolean',
            'tax_reminders_enabled' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'marketing_opt_in' => 'boolean',
            // Encrypted at rest — plain Eloquent casts, no extra package needed.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // Computed, not stored — `name` is split into first_name/last_name
    // columns, but plenty of framework code (Notifiable's default "Hello"
    // greeting, activity log display, etc.) still expects a `name` attribute
    // to just work.
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /** Queued notifications render in this language. */
    public function preferredLocale(): ?string
    {
        return in_array($this->locale, self::SUPPORTED_LOCALES, true) ? $this->locale : null;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isStaff();
    }

    /**
     * A staff account (belongs in the Filament panel) versus a customer
     * account (belongs in the frontend SPA). Same test as canAccessPanel()
     * but without needing a Panel instance — used by
     * App\Notifications\Auth\VerifyEmail to decide which verification link
     * to email.
     */
    public function isStaff(): bool
    {
        // A customer account never becomes staff by accident, even if a
        // permission is later attached to the customer role.
        return $this->hasAnyRole(['super_admin', 'panel_user'])
            || (! $this->hasRole(self::CUSTOMER_ROLE) && $this->getAllPermissions()->isNotEmpty());
    }

    public function isCustomer(): bool
    {
        return $this->hasRole(self::CUSTOMER_ROLE) || $this->customer_id !== null;
    }

    /** Secret for the personal deadlines calendar feed; created on first use. */
    public function calendarToken(bool $reset = false): string
    {
        if ($reset || blank($this->calendar_token)) {
            $this->forceFill(['calendar_token' => Str::random(48)])->save();
        }

        return $this->calendar_token;
    }

    /** Give a portal account the customer role (never to staff). */
    public function markAsCustomer(): void
    {
        if ($this->isStaff() || $this->hasRole(self::CUSTOMER_ROLE)) {
            return;
        }

        $this->assignRole(Role::findOrCreate(self::CUSTOMER_ROLE, 'web'));
    }

    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return filled($this->two_factor_secret) && filled($this->two_factor_confirmed_at);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * App\Notifications\Auth\VerifyEmail picks the right link per audience:
     * customers get the signed `verification.verify` route (routes/api.php)
     * that redirects to the frontend SPA; staff (isStaff()) get the signed,
     * auth-free `staff.verification.verify` route (routes/web.php) that
     * verifies and drops them into the panel — see that class.
     *
     * This method is reached both by Laravel's `Registered` listener (panel
     * AND API registration) and by the panel's resend action (see
     * App\Filament\Auth\EmailVerificationPrompt). Filament's Register page
     * would also fire its own login-gated notification, so that is
     * suppressed in App\Filament\Auth\Register.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);

        $this->verificationNotificationDispatched = true;
    }

    public function hasDispatchedVerificationNotification(): bool
    {
        return $this->verificationNotificationDispatched;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function userSession(): HasOne
    {
        return $this->hasOne(UserSession::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }
}
