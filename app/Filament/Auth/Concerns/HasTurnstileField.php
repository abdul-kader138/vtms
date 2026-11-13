<?php

namespace App\Filament\Auth\Concerns;

use App\Rules\Turnstile;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\ViewField;

/**
 * Shared by App\Filament\Auth\Login and Register — the same Turnstile
 * widget (resources/views/filament/forms/components/turnstile.blade.php)
 * and server-side verification (App\Rules\Turnstile) already used on the
 * customer-facing /api/v1/auth/* endpoints, wired into Filament's own
 * Livewire form validation via ->rule() instead of a FormRequest.
 */
trait HasTurnstileField
{
    protected function getTurnstileFormComponent(): Component
    {
        return ViewField::make('cf_turnstile_response')
            ->view('filament.forms.components.turnstile')
            ->label(false)
            ->rule(new Turnstile(request()?->ip()))
            ->visible(fn (): bool => filled(config('services.turnstile.site_key')));
    }

    /** Cloudflare tokens are single-use, so a rejected submit needs a new one. */
    protected function resetTurnstile(): void
    {
        $this->dispatch('turnstile-reset');
    }
}
