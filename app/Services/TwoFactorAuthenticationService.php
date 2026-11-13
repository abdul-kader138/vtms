<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * TOTP-based 2FA, hand-rolled on pragmarx/google2fa-qrcode rather than a
 * Filament/Fortify 2FA plugin — no such plugin was already a project
 * dependency, and this keeps the whole feature to one small service plus
 * two Filament page classes (Login, EditProfile) with no extra middleware
 * or provider wiring. Secrets/recovery codes are encrypted at rest via
 * plain Eloquent casts on User, not by this class.
 */
class TwoFactorAuthenticationService
{
    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /** Inline SVG <img> src-ready data URI for the setup QR code. */
    public function qrCodeSvg(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeInline(config('app.name'), $user->email, $secret);
    }

    /** $window = how many 30s steps of clock drift either side to tolerate. */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, $window) !== false;
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->all();
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        return in_array($code, $codes, true);
    }

    /** Recovery codes are single-use — removes the matched one and persists. */
    public function consumeRecoveryCode(User $user, string $code): void
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$code])),
        ])->save();
    }
}
