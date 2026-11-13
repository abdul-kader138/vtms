<?php

namespace App\Filament\Auth;

use App\Filament\Auth\Concerns\HasDeviceFingerprintField;
use App\Filament\Auth\Concerns\HasTurnstileField;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceLockService;
use App\Services\TwoFactorAuthenticationService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * Adds a TOTP challenge step on top of Filament's stock credential form —
 * see App\Services\TwoFactorAuthenticationService for why this is hand-rolled
 * rather than a package. Credentials are checked WITHOUT establishing a
 * session (Auth provider's retrieveByCredentials + validateCredentials, the
 * same two calls the base guard's validate() makes internally) whenever a
 * challenge is needed — the user isn't actually logged in until the code
 * verifies, only a pending user id is stashed in the session.
 */
class Login extends BaseLogin
{
    use HasDeviceFingerprintField;
    use HasTurnstileField;

    public bool $twoFactorChallenge = false;

    public bool $showResendVerification = false;

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->twoFactorChallenge) {
            return $this->authenticateTwoFactorChallenge();
        }

        try {
            return $this->authenticateCredentials();
        } catch (ValidationException $exception) {
            $this->resetTurnstile();

            throw $exception;
        }
    }

    protected function authenticateCredentials(): ?LoginResponse
    {
        $this->showResendVerification = false;
        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);

        $provider = Filament::auth()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            $this->throwFailureValidationException();
        }

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
            $this->showResendVerification = true;

            throw ValidationException::withMessages([
                'data.email' => __('Your email address is not verified. We sent a new verification link to your inbox.'),
            ]);
        }

        if ($this->userNeedsTwoFactorChallenge($user)) {
            session([
                'two_factor_authentication_user_id' => $user->getKey(),
                'two_factor_authentication_remember' => $data['remember'] ?? false,
                'two_factor_authentication_device_fingerprint' => $data['device_fingerprint'] ?? null,
            ]);
            $this->startTwoFactorChallenge();

            return null;
        }

        app(DeviceLockService::class)->enforce($user, $data['device_fingerprint'] ?? null);

        Filament::auth()->login($user, $data['remember'] ?? false);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function authenticateTwoFactorChallenge(): ?LoginResponse
    {
        $userId = session('two_factor_authentication_user_id');
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            $this->resetTwoFactorChallenge();
            $this->throwFailureValidationException();
        }

        $code = trim((string) ($this->form->getState()['code'] ?? ''));
        $service = app(TwoFactorAuthenticationService::class);

        $verified = $service->verify($user->two_factor_secret, $code);

        if (! $verified && $service->verifyRecoveryCode($user, $code)) {
            $service->consumeRecoveryCode($user, $code);
            $verified = true;
        }

        if (! $verified) {
            throw ValidationException::withMessages([
                'data.code' => __('The provided two-factor code was invalid.'),
            ]);
        }

        $remember = (bool) session('two_factor_authentication_remember', false);
        $fingerprint = session('two_factor_authentication_device_fingerprint');

        app(DeviceLockService::class)->enforce($user, $fingerprint);

        Filament::auth()->login($user, $remember);
        session()->forget(['two_factor_authentication_user_id', 'two_factor_authentication_remember', 'two_factor_authentication_device_fingerprint']);
        $this->twoFactorChallenge = false;
        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function userNeedsTwoFactorChallenge(User $user): bool
    {
        return (bool) Setting::get('two_factor_enabled', true) && $user->hasEnabledTwoFactorAuthentication();
    }

    protected function startTwoFactorChallenge(): void
    {
        $this->twoFactorChallenge = true;
        $this->hasCachedForms = false;
        $this->form->fill(['code' => '']);
    }

    public function resetTwoFactorChallenge(): void
    {
        $this->twoFactorChallenge = false;
        session()->forget(['two_factor_authentication_user_id', 'two_factor_authentication_remember', 'two_factor_authentication_device_fingerprint']);
        $this->hasCachedForms = false;
        $this->form->fill();
    }

    /**
     * @return array<int|string, string|Form>
     */
    protected function getForms(): array
    {
        if ($this->twoFactorChallenge) {
            return [
                'form' => $this->form(
                    $this->makeForm()
                        ->schema([$this->getCodeFormComponent()])
                        ->statePath('data'),
                ),
            ];
        }

        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                        $this->getTurnstileFormComponent(),
                        $this->getDeviceFingerprintFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getCodeFormComponent(): Component
    {
        return TextInput::make('code')
            ->label(__('Authentication code'))
            ->helperText(__('Enter the 6-digit code from your authenticator app, or one of your recovery codes.'))
            ->required()
            ->autofocus()
            ->autocomplete('one-time-code')
            ->extraInputAttributes(['inputmode' => 'numeric', 'tabindex' => 1]);
    }

    public function getHeading(): string|Htmlable
    {
        return $this->twoFactorChallenge ? __('Two-factor authentication') : parent::getHeading();
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        if (! $this->twoFactorChallenge) {
            return [
                ...parent::getFormActions(),
                Action::make('resendVerification')
                    ->label(__('Resend verification email'))
                    ->link()
                    ->visible(fn (): bool => $this->showResendVerification)
                    ->url(route('customer.verification-notice')),
            ];
        }

        return [
            $this->getAuthenticateFormAction()->label(__('Verify')),
            Action::make('backToLogin')
                ->label(__('Back to login'))
                ->link()
                ->action('resetTwoFactorChallenge'),
        ];
    }
}
