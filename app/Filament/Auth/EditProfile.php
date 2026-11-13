<?php

namespace App\Filament\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\HtmlString;

/**
 * Adds an avatar upload and 2FA self-service management on top of Filament's
 * stock profile page — the enable/disable/recovery-code actions live here
 * (getHeaderActions()) rather than a dedicated route, matching the pattern
 * of everything else on this page being "one self-contained profile screen."
 * See App\Filament\Auth\Login for the other half (the login-time challenge).
 */
class EditProfile extends BaseEditProfile
{
    /**
     * @return array<int|string, string|Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        Grid::make(3)->schema([
                            Section::make(__('Profile Picture'))
                                ->schema([
                                    FileUpload::make('avatar')
                                        ->label('')
                                        ->avatar()
                                        ->imageEditor()
                                        ->circleCropper()
                                        ->disk('public')
                                        ->directory('avatars')
                                        ->visibility('public'),
                                ])
                                ->columnSpan(1),

                            Section::make(__('Personal Information'))
                                ->schema([
                                    Grid::make(2)->schema([
                                        $this->getFirstNameFormComponent(),
                                        $this->getLastNameFormComponent(),
                                    ]),
                                    $this->getEmailFormComponent(),
                                    TextInput::make('phone')->label(__('Phone number'))->tel()->maxLength(30)
                                        ->rules([new \App\Rules\ValidPhoneNumber])
                                        ->dehydrateStateUsing(fn ($state) => \App\Support\PhoneNumber::normalize($state))
                                        ->helperText(__('Include the country code, for example +39 333 123 4567.')),
                                    TextInput::make('tax_id')->label(__('Tax ID'))->maxLength(100),
                                    Textarea::make('address')->label(__('Address'))->rows(3)->maxLength(1000),
                                    Select::make('locale')
                                        ->label(__('Language'))
                                        ->options(['en' => __('English'), 'it' => __('Italiano'), 'bn' => __('বাংলা')])
                                        ->placeholder(__('Use system default'))
                                        ->native(false)
                                        ->helperText(__('This preference applies only to your account.')),
                                ])
                                ->columnSpan(2),
                        ]),

                        Section::make(__('Change Password'))
                            ->icon('heroicon-o-lock-closed')
                            ->description(__('Leave both fields blank to keep your current password.'))
                            ->schema([
                                Grid::make(2)->schema([
                                    $this->getPasswordFormComponent(),
                                    $this->getPasswordConfirmationFormComponent(),
                                ]),
                            ]),

                        Section::make(__('Two-Factor Authentication'))
                            ->schema([
                                Placeholder::make('two_factor_status')
                                    ->label('')
                                    ->content(fn () => $this->twoFactorStatusText()),
                            ]),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data'),
            ),
        ];
    }

    // User has no `name` column (see App\Models\User's computed `name`
    // accessor) — first_name/last_name replace the base class's single
    // getNameFormComponent().
    protected function getFirstNameFormComponent(): Component
    {
        return TextInput::make('first_name')
            ->label(__('First name'))
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getLastNameFormComponent(): Component
    {
        return TextInput::make('last_name')
            ->label(__('Last name'))
            ->required()
            ->maxLength(255);
    }

    // Strength itself (min length, mixed case, numbers) is enforced by the
    // Password::default() rule the base component already applies — see
    // AppServiceProvider::boot(). This just surfaces the requirement here
    // too, instead of letting the user find out from a rejected form.
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label(__('New password'))
            ->prefixIcon('heroicon-o-key')
            ->placeholder('••••••••')
            ->helperText(__('At least 8 characters, with uppercase, lowercase, and a number.'));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label(__('Confirm new password'))
            ->prefixIcon('heroicon-o-key')
            ->placeholder('••••••••');
    }

    private function twoFactorStatusText(): string
    {
        if (! $this->twoFactorGloballyEnabled()) {
            return __('Two-factor authentication is currently disabled application-wide by an administrator.');
        }

        return $this->getUser()->hasEnabledTwoFactorAuthentication()
            ? __('Two-factor authentication is enabled on your account.')
            : __('Two-factor authentication is not enabled on your account.');
    }

    private function twoFactorGloballyEnabled(): bool
    {
        return (bool) Setting::get('two_factor_enabled', true);
    }

    /**
     * All four actions are always returned (never conditionally omitted)
     * and gated purely via ->visible() closures — deliberately, so a test
     * (or anything else introspecting actions) can assert visibility
     * rather than existence. Only the "globally disabled" case is a real
     * absence, since there is nothing to manage at all in that state.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        if (! $this->twoFactorGloballyEnabled()) {
            return [];
        }

        /** @var User $user */
        $user = $this->getUser();
        $isEnabled = fn () => $user->fresh()->hasEnabledTwoFactorAuthentication();

        return [
            $this->enableTwoFactorAction($user)->visible(fn () => ! $isEnabled()),

            Action::make('showRecoveryCodes')
                ->label(__('Show Recovery Codes'))
                ->icon('heroicon-o-key')
                ->color('gray')
                ->visible($isEnabled)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close'))
                ->modalContent(fn () => view('filament.auth.recovery-codes-modal', [
                    'codes' => $user->two_factor_recovery_codes ?? [],
                ])),

            Action::make('regenerateRecoveryCodes')
                ->label(__('Regenerate Recovery Codes'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible($isEnabled)
                ->requiresConfirmation()
                ->modalDescription(__('Your existing recovery codes will stop working immediately.'))
                ->action(function () use ($user) {
                    $user->forceFill([
                        'two_factor_recovery_codes' => app(TwoFactorAuthenticationService::class)->generateRecoveryCodes(),
                    ])->save();

                    Notification::make()->success()->title(__('Recovery codes regenerated'))->send();
                }),

            Action::make('disableTwoFactor')
                ->label(__('Disable Two-Factor Authentication'))
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->visible($isEnabled)
                ->requiresConfirmation()
                ->modalHeading(__('Disable two-factor authentication?'))
                ->modalDescription(__('You will no longer be asked for a code when signing in.'))
                ->action(function () use ($user) {
                    $user->forceFill([
                        'two_factor_secret' => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_confirmed_at' => null,
                    ])->save();

                    Notification::make()->success()->title(__('Two-factor authentication disabled'))->send();
                }),
        ];
    }

    private function enableTwoFactorAction(User $user): Action
    {
        $service = app(TwoFactorAuthenticationService::class);

        return Action::make('enableTwoFactor')
            ->label(__('Enable Two-Factor Authentication'))
            ->icon('heroicon-o-shield-check')
            ->fillForm(fn () => ['secret' => $service->generateSecretKey()])
            ->form([
                Hidden::make('secret'),
                Placeholder::make('qr_code')
                    ->label(__('Scan this with your authenticator app'))
                    ->content(fn (Get $get) => new HtmlString(
                        '<div style="display:flex;flex-direction:column;align-items:center;gap:.5rem;">'
                        .'<img src="'.$service->qrCodeSvg($user, (string) $get('secret')).'" alt="'.e(__('Two-factor setup QR code')).'" />'
                        .'<code style="font-size:.75rem;">'.e((string) $get('secret')).'</code>'
                        .'</div>'
                    )),
                TextInput::make('code')
                    ->label(__('Authentication Code'))
                    ->helperText(__('Enter the 6-digit code your app just generated to confirm setup.'))
                    ->required()
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($service, $get) {
                        if (! $service->verify((string) $get('secret'), (string) $value)) {
                            $fail(__('The provided code was invalid.'));
                        }
                    }),
            ])
            ->action(function (array $data) use ($user, $service) {
                $user->forceFill([
                    'two_factor_secret' => $data['secret'],
                    'two_factor_recovery_codes' => $service->generateRecoveryCodes(),
                    'two_factor_confirmed_at' => now(),
                ])->save();

                Notification::make()->success()->title(__('Two-factor authentication enabled'))->send();
            });
    }
}
